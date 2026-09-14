# A native PHP extension built with ext-php-rs against the exact PHP that will
# load it (ZTS or not, FrankenPHP-ready). Used for:
#
#   - wp_d1_client: a native HTTP client for the Cloudflare D1 proxy protocol,
#     holding a connection pool that persists across requests.
#   - wp_mysql_parser: upstream's accelerated MySQL lexer/parser.
#
#   mkPhpExtension { pkgs; php; pname; src; }
{
  pkgs,
  php,
  pname,
  src,
  # The Rust toolchain can come from a newer package set than the PHP build.
  rustPkgs ? pkgs,
  # Build-time tools a crate needs beyond bindgen (pkg-config for TLS stacks).
  extraNativeBuildInputs ? [ ],
}:
let
  # Only the crate directory: a source hash that depends on the whole flake
  # would rebuild the crate on every unrelated commit. The lock file is read
  # from the original path: the narrowed copy exists only once it is built,
  # and `nix flake check` reads the lock before then.
  crate = builtins.path {
    path = src;
    name = "${pname}-src";
  };
in
rustPkgs.rustPlatform.buildRustPackage {
  inherit pname;
  version = "0.1.0";
  src = crate;
  cargoLock.lockFile = "${src}/Cargo.lock";

  # ext-php-rs generates bindings against the PHP headers at build time.
  nativeBuildInputs = [ rustPkgs.rustPlatform.bindgenHook ] ++ extraNativeBuildInputs;
  env = {
    PHP_CONFIG = "${php.unwrapped.dev}/bin/php-config";
    PHP = "${php.unwrapped}/bin/php";
  };

  # The crates' tests require a live PHP runtime; extension correctness is
  # verified by the driver test suites instead.
  doCheck = false;

  meta.license = pkgs.lib.licenses.gpl2Plus;
}
