# Copy

Copy objects or directory/prefix contents between any two locations.

`copy-to` is an alias of `copy` with identical semantics.

## Usage

```text
storage copy <source> <destination> [options]
storage copy-to <source> <destination> [options]
```

## Examples

```bash
# Copy a remote prefix to another remote
storage copy do-spaces-nyc:my-data/videos/ do-spaces-sgp:backup/movies/

# Copy a single file
storage copy do-spaces-nyc:my-data/report.pdf do-spaces-sgp:backup/report.pdf

# Copy local → remote
storage copy /home/user/backups/ do-spaces-nyc:my-data/backups/ --dry-run

# Overwrite existing destination objects
storage copy src:bucket/data/ dst:bucket/data/ --overwrite

# Live progress
storage copy src:bucket/large-files/ dst:bucket/archive/ --progress

# Queue the operation instead of running it now
storage copy src:bucket/data/ dst:bucket/backup/ --queue
```

## Directory semantics

Copying a directory/prefix copies its **contents** into the destination,
preserving relative structure:

```text
src:bucket/documents/           dst:bucket/archive/
├── report.pdf        →         ├── report.pdf
├── invoice.pdf       →         ├── invoice.pdf
└── 2026/             →         └── 2026/
    └── january.pdf                  └── january.pdf
```

The source directory name is never nested automatically. To keep it, specify
the destination explicitly:

```bash
storage copy src:bucket/documents/ dst:bucket/archive/documents/
```

If the destination prefix already exists, source contents **merge** into it —
existing destination objects are kept, not deleted.

## Copying into a directory

A single object copied to a trailing-slash destination keeps its filename:

```bash
storage copy src:bucket/report.pdf dst:bucket/documents/
# → dst:bucket/documents/report.pdf
```

## Options

| Option | Description |
| --- | --- |
| `--overwrite` | Replace existing destination objects (default: keep them) |
| `--dry-run` | Preview (counts predicted from a listing) without changes |
| `--transfers=N` | Parallel transfers (default: 8) |
| `--retries=N` | Retries on failure (default: 3) |
| `--progress` | Live transfer progress (interactive terminals) |
| `--acl=...` | ACL for written objects |
| `--queue` | Dispatch as a background job |
| `-v`, `--verbose` | Verbose rclone output + stack trace on errors |

## Cross-storage

All combinations work through the same path semantics:

```text
Remote → Remote
Remote → Local
Local → Remote
Local → Local
```

## Notes

- **No-overwrite by default**: existing destination objects are skipped
  (`--ignore-existing`) unless `--overwrite`.
- Any transfer with a remote end runs as **one rclone process** — no per-object
  subprocess loops.
- The reported `copied` / `skipped` / `failed` counts come from rclone's own
  final counters, never from a pre-transfer estimate (except `--dry-run`).

## Related

- [Path Syntax](Path-Syntax)
- [Move](Move) — copy, then delete the source
- [Duplicate](Duplicate) — copy and keep the source
- [Queue & Retry](Queue-and-Retry)