# Upload

Push local files or directories to a remote storage path.

## Usage

```text
storage upload <source> <destination> [options]
```

## Examples

```bash
# Upload a local directory to a remote prefix
storage upload /home/user/photos/ do-spaces-nyc:my-data/photos/

# Upload a single file
storage upload /home/user/report.pdf do-spaces-nyc:my-data/reports/report.pdf

# Dry run
storage upload /home/user/backups/ do-spaces-nyc:my-data/backups/ --dry-run

# Overwrite matching destination objects
storage upload /home/user/data/ do-spaces-nyc:my-data/data/ --overwrite
```

## Directory semantics

A directory upload preserves relative structure inside the destination:

```
/home/user/documents/                do-spaces-nyc:my-data/documents/
├── report.pdf        →              ├── report.pdf
└── 2026/             →              └── 2026/
    └── january.pdf                        └── january.pdf
```

Contents land in the destination prefix; the source directory name is never
nested unless you include it in the destination path.

## Options

Identical to [[Copy#options]]: `--overwrite`, `--dry-run`, `--transfers`,
`--retries`, `--progress`, `--acl`, `--queue`, `-v/--verbose`.

## Notes

- A **missing source** exits `3`.
- Uploads run as a single rclone process.
- `--acl` / `STORAGE_DEFAULT_ACL` control the ACL applied to written objects —
  see [[Acl-Handling|ACL Handling]].

## Related

- [[Download]] — the reverse direction
- [[Copy]] — same engine and semantics