# The Turso snapshot publisher.
#
# A live Turso embedded replica cannot be read by pdo_sqlite: Turso holds an
# exclusive lock on it for the life of its connection and coordinates its WAL
# through a file SQLite knows nothing about. This binary owns the replica and
# hands PHP a plain SQLite file instead -- pull, VACUUM INTO, rollback-journal,
# read-only, atomic rename. See packages/turso-snapshot-publisher/README.md.
#
#   mkTursoPublisher { inherit pkgs; }
{
  pkgs,
  src,
  # The Rust toolchain can come from a newer package set than the rest.
  rustPkgs ? pkgs,
}:
let
  # Only the crate directory: a source hash that depends on the whole flake
  # would rebuild the crate on every unrelated commit.
  crate = builtins.path {
    path = "${src}/packages/turso-snapshot-publisher";
    name = "turso-snapshot-publisher-src";
  };
in
rustPkgs.rustPlatform.buildRustPackage {
  pname = "turso-snapshot-publisher";
  version = "0.1.0";

  src = crate;

  cargoLock.lockFile = "${crate}/Cargo.lock";

  # turso_core pulls in ring through hyper-rustls.
  nativeBuildInputs = with rustPkgs; [ pkg-config ];

  # The crate has no tests of its own; the driver's conformance suite and the
  # package README's measurements cover it.
  doCheck = false;

  meta = {
    description = "Publishes a readable SQLite snapshot of a Turso database";
    mainProgram = "turso-snapshot-publisher";
    license = pkgs.lib.licenses.gpl2Plus;
  };
}
