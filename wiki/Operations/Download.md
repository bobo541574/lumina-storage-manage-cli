# Download

Fetch objects or directory/prefix contents from storage to a local path.

## Usage

```text
storage download <source> <destination> [options]
```

## Examples

```bash
# Download a remote prefix into a local directory
storage download do-spaces-nyc:my-data/videos/ /home/user/videos/

# Download a single object to a file
storage download do-spaces-nyc:my-data/report.pdf /home/user/report.pdf

# Dry run
storage download do-spaces-nyc:my-data/backups/ /home/user/backup/ --dry-run
```

## Directory semantics

A prefix download preserves relative structure **inside** the destination:

```
do-spaces-nyc:my-data/documents/         /home/user/backup/documents/
├── report.pdf        →                  ├── report.pdf
└── 2026/             →                  └── 2026/
    └── january.pdf                            └── january.pdf
```

Never `.../documents/documents/report.pdf` — unless you point the destination at
an explicit `documents/` subpath yourself.

## Options

Identical to [Copy#options](Copy#options): `--overwrite`, `--dry-run`, `--transfers`,
`--retries`, `--progress`, `--acl`, `--queue`, `-v/--verbose`.

(`--acl` applies to the transfer if the destination remote writes ACLs; local
downloads are unaffected.)

## Notes

- A **missing source** exits `3`.
- Transfers with a remote end run as a single rclone process.
- Result counts come from rclone's final counters (estimated only for
  `--dry-run`).

## Related

- [Upload](Upload) — the reverse direction
- [Copy](Copy) — same engine and semantics