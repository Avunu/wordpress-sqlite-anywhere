{
  description = "WordPress SQLite Anywhere — WordPress on SQLite, Turso or Cloudflare D1";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    flake-utils.url = "github:numtide/flake-utils";
    composition-c4.url = "github:fossar/composition-c4";
    git-hooks = {
      url = "github:cachix/git-hooks.nix";
      inputs.nixpkgs.follows = "nixpkgs";
    };

    # upstream/ is a git submodule (WordPress/sqlite-database-integration at a
    # release tag). Fetch it with the flake, so `self` carries the driver source
    # the assembler needs. Consumers must reference this flake over git with
    # `?submodules=1`, never as a GitHub tarball.
    self.submodules = true;
  };

  outputs =
    {
      self,
      nixpkgs,
      flake-utils,
      composition-c4,
      git-hooks,
    }:
    flake-utils.lib.eachDefaultSystem (
      system:
      let
        pkgs = import nixpkgs {
          inherit system;
          overlays = [ composition-c4.overlays.default ];
        };
        inherit (pkgs) lib stdenvNoCC;

        # PHP 8.5: the plugin requires it (the header says so and the array
        # statement dropped its PHP < 8 branch). On 8.5 opcache is built into
        # the interpreter, so there is no JIT gate to fight in ZTS builds.
        php = pkgs.php85.buildEnv {
          extensions =
            { enabled, all }:
            enabled
            ++ (with all; [
              pdo
              pdo_sqlite
              curl
              mbstring
              openssl
              tokenizer
              fileinfo
            ]);
          # PHPStan parses the full WordPress stubs; the 128M default is not enough.
          extraConfig = ''
            memory_limit = 2G
          '';
        };

        # nixpkgs wraps both tools around the default PHP (8.4); the root
        # composer project's platform check requires 8.5, so bind them to ours.
        # php85Packages.php-codesniffer exists too, but that is a plain PHP 8.5
        # rather than this buildEnv.
        phpstan = pkgs.phpstan.override { inherit php; };
        phpcs = pkgs.php85Packages.php-codesniffer.override { inherit php; };

        composerData = builtins.fromJSON (builtins.readFile ./composer.json);

        pname = "wordpress-sqlite-anywhere";
        inherit (composerData) version;
        src = self;

        # -------------------------------------------------------------- #
        # PHP / Composer vendor dependencies                              #
        # c4.fetchComposerDeps reads composer.lock per-package via        #
        # builtins.fetchGit — no hash to bump by hand.                    #
        # -------------------------------------------------------------- #
        composerDeps = pkgs.c4.fetchComposerDeps {
          inherit src;
        };

        # The bundled driver's suites are upstream's and use PHPUnit 8/9 APIs,
        # and WordPress Coding Standards needs PHP_CodeSniffer 3.x where
        # nixpkgs ships 4.x: both live in a second, separately locked project.
        driverToolsDeps = pkgs.c4.fetchComposerDeps {
          lockFile = ./driver-tests/tools-project/composer.lock;
        };

        driverTools = stdenvNoCC.mkDerivation {
          pname = "${pname}-driver-tools";
          inherit version;
          src = ./driver-tests/tools-project;
          composerDeps = driverToolsDeps;

          nativeBuildInputs = [
            php
            php.packages.composer
            pkgs.c4.composerSetupHook
          ];

          buildPhase = ''
            runHook preBuild
            composer --no-ansi install --no-interaction
            runHook postBuild
          '';

          installPhase = ''
            runHook preInstall
            mkdir -p "$out"
            # -L: c4 installs vendor as symlinks into the store; phpunit and
            # phpcs resolve paths through them.
            cp -rL vendor "$out/"
            runHook postInstall
          '';
        };

        # ---------------------------------------------------------------- #
        # The assembled tree: upstream driver + patches + our overlays.     #
        # bin/assemble.sh needs only coreutils and GNU patch, so it runs    #
        # unchanged here. A patch that no longer applies fails this build,  #
        # and with it every check that depends on it.                      #
        # ---------------------------------------------------------------- #
        assembled = stdenvNoCC.mkDerivation {
          pname = "${pname}-assembled";
          inherit version src;

          nativeBuildInputs = [ pkgs.gnupatch ];

          dontConfigure = true;
          dontBuild = true;
          # Keep the tree byte for byte: fixup would rewrite shebangs in
          # upstream's test tools.
          dontFixup = true;

          installPhase = ''
            runHook preInstall
            bash bin/assemble.sh --out "$out"
            runHook postInstall
          '';
        };

        # ---------------------------------------------------------------- #
        # Final plugin assembly                                            #
        # ---------------------------------------------------------------- #
        pluginPackage = stdenvNoCC.mkDerivation {
          inherit
            pname
            version
            src
            composerDeps
            ;

          nativeBuildInputs = [
            php
            php.packages.composer
            pkgs.c4.composerSetupHook
          ];

          buildPhase = ''
            runHook preBuild
            composer --no-ansi install --no-dev --no-interaction --optimize-autoloader
            runHook postBuild
          '';

          installPhase = ''
            runHook preInstall

            pluginDir="$out/share/wordpress/plugins/${pname}"
            mkdir -p "$(dirname "$pluginDir")"

            cp -r ${assembled}/packages/plugin-sqlite-database-integration "$pluginDir"
            chmod -R u+w "$pluginDir"
            # -L dereferences: composition-c4 installs vendor/ as symlinks into the
            # Nix store; the distributable plugin must contain real, self-contained files.
            cp -rL vendor "$pluginDir/vendor"

            # Stamp the WordPress plugin header version from composer.json, which is the
            # single source of truth (Release Please bumps it). WordPress and the update
            # checker read this header to detect new versions.
            sed -i -E "s|^([[:space:]]*\* Version:[[:space:]]*).*|\1${version}|" "$pluginDir/${pname}.php"

            runHook postInstall
          '';

          meta = {
            inherit (composerData) description;
            license = lib.licenses.gpl2Plus;
            platforms = lib.platforms.all;
          };
        };

        # The three native pieces, as functions so wordpress-nix can build them
        # against its own PHP / Rust toolchain (see `lib` below).
        mkTursoPublisher = import ./nix/turso-publisher.nix;
        mkPhpExtension = import ./nix/php-extension.nix;

        tursoPublisher = mkTursoPublisher {
          inherit pkgs;
          src = self;
        };

        d1Client = mkPhpExtension {
          inherit pkgs php;
          pname = "wp_d1_client";
          src = "${self}/packages/php-ext-wp-d1-client";
        };

        # ---------------------------------------------------------------- #
        # The bundled driver's test suites, over the assembled package.     #
        # The tooling project's vendor/ is linked in as vendor/;            #
        # tests/bootstrap-anywhere.php loads src/load.php itself, which is   #
        # the one thing upstream's autoloader would have done.              #
        # ---------------------------------------------------------------- #
        mkDriverCheck =
          {
            name,
            testsuite,
            backend ? null,
          }:
          pkgs.runCommand "check-driver-${name}"
            {
              nativeBuildInputs = [ php ];
              env = lib.optionalAttrs (backend != null) { WP_SQLITE_TEST_BACKEND = backend; };
            }
            ''
              set -euo pipefail
              # The whole packages/ tree: upstream's WP_SQLite_DB_Tests reaches
              # into the sibling plugin package by relative path.
              cp -r ${assembled}/packages work
              chmod -R u+w work
              cd work/mysql-on-sqlite
              ln -s ${driverTools}/vendor vendor
              export HOME="$TMPDIR"
              php vendor/bin/phpunit --no-coverage --colors=never --testsuite ${testsuite}
              touch "$out"
            '';

        # ------------------------------------------------------------------ #
        # git-hooks.nix — local pre-push quality gates. The PHP hooks need    #
        # composer vendor trees and the assembled build/, which the read-only #
        # `nix flake check` sandbox does not have, so they run at the         #
        # `pre-push` stage: installed locally by the devShell shellHook,      #
        # skipped by the sandboxed flake check. CI covers the same ground via #
        # the flake checks.                                                   #
        # ------------------------------------------------------------------ #
        preCommitCheck = git-hooks.lib.${system}.run {
          src = self;
          hooks = {
            phpstan = {
              enable = true;
              name = "phpstan (level 8, WordPress-aware)";
              package = phpstan;
              entry = "composer phpstan";
              files = "\\.php$|\\.copy$";
              pass_filenames = false;
              stages = [ "pre-push" ];
            };
            phpcs = {
              enable = true;
              name = "phpcs (PSR-12, house-style paths)";
              entry = "${phpcs}/bin/phpcs";
              files = "\\.php$|\\.copy$";
              pass_filenames = false;
              stages = [ "pre-push" ];
            };
            phpcs-driver = {
              enable = true;
              name = "phpcs (WordPress, driver overlays)";
              entry = "driver-tests/tools-project/vendor/bin/phpcs --standard=phpcs-driver.xml.dist";
              files = "^(driver|driver-tests|plugin)/.*\\.php$";
              pass_filenames = false;
              stages = [ "pre-push" ];
            };
            cargo-fmt = {
              enable = true;
              name = "cargo fmt --check (both crates)";
              entry = "${pkgs.writeShellScript "cargo-fmt-check" ''
                set -e
                for crate in packages/turso-snapshot-publisher packages/php-ext-wp-d1-client; do
                  ${pkgs.cargo}/bin/cargo fmt --manifest-path "$crate/Cargo.toml" --check
                done
              ''}";
              files = "\\.rs$";
              pass_filenames = false;
              stages = [ "pre-push" ];
            };

            nixfmt.enable = true;
            statix.enable = true;
            deadnix.enable = true;
          };
        };
      in
      {
        devShells.default = pkgs.mkShell {
          inherit (preCommitCheck) shellHook;
          packages = [
            php
            php.packages.composer
            phpstan
            phpcs
            pkgs.nodejs_22
            pkgs.cargo
            pkgs.rustc
            pkgs.clippy
            pkgs.rustfmt
            pkgs.pkg-config
            pkgs.gnupatch
            pkgs.git
            pkgs.sqlite
            # tursodb: a local sync server for the live Turso probe
            # (driver/turso/verify-against-server.php).
            pkgs.turso
          ]
          ++ preCommitCheck.enabledPackages;
        };

        checks = {
          # Patches apply, overlays do not collide, version.php is untouched.
          inherit assembled;

          # The committed plugin header must agree with composer.json:
          # plugin-update-checker reads it from the git tag, not from the zip.
          plugin-header = pkgs.runCommand "check-plugin-header" { inherit src; } ''
            bash "$src/bin/check-plugin-header.sh"
            touch "$out"
          '';

          # PHPStan level 8 over the house-style code and the driver overlays.
          # Upstream's classes are resolved from the assembled tree via
          # scanDirectories; the committed config points at ./build for local
          # runs, and the store path is substituted here.
          phpstan = stdenvNoCC.mkDerivation {
            name = "${pname}-phpstan-${version}";
            inherit src composerDeps;

            nativeBuildInputs = [
              php
              php.packages.composer
              phpstan
              pkgs.c4.composerSetupHook
            ];

            buildPhase = ''
              runHook preBuild
              composer --no-ansi install --no-interaction
              ln -s ${assembled} build
              phpstan analyse --no-progress --no-ansi --memory-limit=2G
              runHook postBuild
            '';

            installPhase = "touch $out";
          };

          # PSR-12 for the house-style paths, with nixpkgs' PHP_CodeSniffer 4.
          phpcs =
            pkgs.runCommand "check-phpcs"
              {
                nativeBuildInputs = [
                  php
                  phpcs
                ];
                inherit src;
              }
              ''
                set -euo pipefail
                cd "$src"
                export HOME="$TMPDIR"
                phpcs -q -d memory_limit=1G --report=full
                touch "$out"
              '';

          # WordPress Coding Standards for the driver overlays, with the
          # tooling project's PHP_CodeSniffer 3 + WPCS.
          phpcs-driver =
            pkgs.runCommand "check-phpcs-driver"
              {
                nativeBuildInputs = [ php ];
                inherit src;
              }
              ''
                set -euo pipefail
                cp -r "$src" work
                chmod -R u+w work
                cd work
                ln -s ${driverTools}/vendor driver-tests/tools-project/vendor
                export HOME="$TMPDIR"
                php driver-tests/tools-project/vendor/bin/phpcs -q -d memory_limit=1G \
                  --standard=phpcs-driver.xml.dist --report=full
                touch "$out"
              '';

          # The plugin's own suite (PHPUnit 12, src/).
          phpunit = stdenvNoCC.mkDerivation {
            name = "${pname}-phpunit-${version}";
            inherit src composerDeps;

            nativeBuildInputs = [
              php
              php.packages.composer
              pkgs.c4.composerSetupHook
            ];

            buildPhase = ''
              runHook preBuild
              composer --no-ansi install --no-interaction
              ln -s ${assembled} build
              php vendor/bin/phpunit --no-coverage --colors=never
              runHook postBuild
            '';

            installPhase = "touch $out";
          };

          # The bundled driver's suites against each backend.
          driver-pdo = mkDriverCheck {
            name = "pdo";
            testsuite = "driver";
          };
          driver-d1 = mkDriverCheck {
            name = "d1";
            testsuite = "remote";
            backend = "d1";
          };
          driver-turso = mkDriverCheck {
            name = "turso";
            testsuite = "remote";
            backend = "turso";
          };

          # The Rust pieces build, are formatted, and are clippy-clean.
          publisher = tursoPublisher.overrideAttrs (prev: {
            nativeBuildInputs = (prev.nativeBuildInputs or [ ]) ++ [
              pkgs.rustfmt
              pkgs.clippy
            ];
            postBuild = (prev.postBuild or "") + ''
              cargo fmt --check
              cargo clippy --offline --all-targets -- -D warnings
            '';
          });
          d1-client = d1Client;

          # nix flake check also validates the git-hooks config (sandbox-safe hooks
          # only; the pre-push PHP hooks are skipped here).
          pre-commit = preCommitCheck;
        };

        packages = {
          default = pluginPackage;
          inherit assembled;

          # The assembled driver package alone, for tooling that wants the
          # MySQL-on-SQLite library without the WordPress plugin around it.
          driver = pkgs.runCommand "${pname}-driver-${version}" { } ''
            cp -r ${assembled}/packages/mysql-on-sqlite "$out"
          '';

          turso-snapshot-publisher = tursoPublisher;
          d1-client = d1Client;

          # ---------------------------------------------------------------- #
          # Deterministic, ready-to-install zip (top-level wordpress-sqlite-anywhere/).
          # nix build .#zip -> result/wordpress-sqlite-anywhere.zip
          # ---------------------------------------------------------------- #
          zip = stdenvNoCC.mkDerivation {
            name = "${pname}-zip-${version}";
            nativeBuildInputs = [ pkgs.zip ];
            buildCommand = ''
              mkdir -p tmp/${pname}
              cp -r ${pluginPackage}/share/wordpress/plugins/${pname}/. tmp/${pname}/
              chmod -R u+w tmp
              mkdir -p "$out"
              (cd tmp && zip -q -r -X "$out/${pname}.zip" ${pname})
            '';
          };
        };
      }
    )
    // {
      # Builders for consumers (wordpress-nix) that need the native pieces
      # against their own PHP or Rust toolchain.
      lib = {
        # mkTursoPublisher { pkgs; src ? self; rustPkgs ? pkgs; }
        mkTursoPublisher =
          args:
          import ./nix/turso-publisher.nix (
            {
              src = self;
            }
            // args
          );

        # mkD1ClientExtension { pkgs; php; rustPkgs ? pkgs; }
        mkD1ClientExtension =
          args:
          import ./nix/php-extension.nix (
            args
            // {
              pname = "wp_d1_client";
              src = "${self}/packages/php-ext-wp-d1-client";
            }
          );

        # mkMysqlParserExtension { pkgs; php; rustPkgs ? pkgs; }
        # The crate is upstream's; it comes from the submodule.
        mkMysqlParserExtension =
          args:
          import ./nix/php-extension.nix (
            args
            // {
              pname = "wp_mysql_parser";
              src = "${self}/upstream/packages/php-ext-wp-mysql-parser";
            }
          );

        # Source paths for consumers that bundle rather than build.
        srcs = {
          d1ProxyWorker = "${self}/packages/d1-proxy-worker";
        };
      };
    };
}
