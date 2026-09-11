# Move

Move objects or directory/prefix contents between locations with **copy →
verify → delete source** semantics.

`move-to` is an alias of `move` with identical semantics. [Rename](Rename) is also
built on the same engine.

## Usage

```text
storage move <source> <destination> [options]
storage move-to <source> <destination> [options]
```

## Examples

```bash
# Move a prefix between remotes
storage move do-spaces-nyc:my-data/archive/ do-spaces-sgp:backup/archive/

# Move a single file (and relocate it into a sub-prefix)
storage move do-spaces-nyc:my-data/old-report.pdf do-spaces-nyc:my-data/archive/old-report.pdf

# Dry run
storage move src:bucket/data/ dst:bucket/data/ --dry-run

# Live progress
storage move src:bucket/large-files/ dst:bucket/archive/ --progress
```

## Semantics

Each object is:

```text
COPY
 ↓
VERIFY (destination exists)
 ↓
DELETE source object
```

- **Only copied-and-verified objects are deleted** from the source.
- **Failed objects remain** in place and are reported — see
  [Progress & Reporting](Progress-and-Reporting).
- **Skipped objects** (destination already exists, no `--overwrite`) keep their
  source copy.
- Emptied source prefixes are removed (rclone `--delete-empty-src-dirs`).
- A **dry run** short-circuits before any write or delete.

## How the backend executes it

- **Any transfer with a remote end** runs as a single rclone process
  (`move` / `moveto`), which performs copy → verify → delete per object in
  parallel, and server-side where the backend supports it.
- **Local↔local** moves drive a per-object loop: exist-check, copy, verify,
  delete.

## Options

Identical to [Copy#options](Copy#options): `--overwrite`, `--dry-run`, `--transfers`,
`--retries`, `--progress`, `--acl`, `--queue`, `-v/--verbose`.

## Partial results

If some objects fail, the command reports partial completion and exits `6`:

```text
  PARTIAL   Moved 98, skipped 0, failed 2 objects.  (12.4s)
  PARTIAL   Operation completed with errors.
```

The failed source objects remain intact and can be retried.

## Related

- [Path Syntax](Path-Syntax)
- [Copy](Copy) — keep the source
- [Rename](Rename) — same semantics, "same-ish" locations
- [Queue & Retry](Queue-and-Retry)