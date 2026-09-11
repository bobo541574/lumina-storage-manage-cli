# Migration Guide: `multi-remote-transfer.sh` → Storage CLI

This document maps every feature of the legacy Bash helper to the new Laravel
Zero Storage CLI. The new CLI is a **redesign**, not a translation: the same
operations exist, but with explicit commands, canonical path syntax, unified
exit codes, and shared services for interactive and non-interactive flows.

## Feature Mapping

| Legacy Bash | New Storage CLI | Notes |
| --- | --- | --- |
| `--operation copy` | `storage copy <source> <destination>` | Same directory/prefix semantics |
| `--operation move` | `storage move <source> <destination>` | Copy -> verify -> delete source, as a single rclone `move` |
| `--operation download` | `storage download <source> <destination>` | Remote → local |
| `--operation upload` | `storage upload <source> <destination>` | Local → remote |
| `--operation list` | `storage list <path>` | `--recursive`, `--type` preserved |
| `--operation set-acl` | `storage visibility <path> <private\|public>` | Raw ACL values replaced |
| `--operation sync` | — | Legacy parity only; not built into the first release |
| Interactive setup / saved configs | `storage wizard` | New interactive wizard; save with `--save-as=NAME` |
| Saved config reuse | `storage wizard --config=NAME` | Prefills wizard answers from a saved configuration |
| Saved config management | `storage configs` / `configs:save` / `configs:forget` | Named configurations stored in the `saved_configs` table |
| Long-running jobs | `storage copy --queue` → `queue:work` → `storage retry <id>` | Async processing with `failed_jobs` retry support |
| `--source-remote/--source-bucket/--source-path` | `remote:bucket/path` in one positional argument | Canonical `<remote>:<bucket>/<path>` syntax |
| `--dest-remote/--dest-bucket/--dest-path` | `destination` positional argument | Mirrors source by default via prefix contents |
| `--transfers N` | `--transfers=N` | Same default (8), now configurable in `config/storage.php` |
| `ACL="private"` default + `--s3-acl=$ACL` on every run | `STORAGE_DEFAULT_ACL` | Unset by default: the destination remote's own `acl` is used instead. Set `STORAGE_DEFAULT_ACL=private` to reproduce the script's behaviour exactly |
| `--retries N` | `--retries=N` | Same default (3), now configurable in `config/storage.php` |
| `--dry-run` | `--dry-run` | Present on all transfer/visibility/delete commands |
| `--verbose` | `-v/--verbose` | Global option (implemented by Laravel Zero) |
| `--recursive` (set-acl) | `visibility --recursive` | Same meaning |
| `--ls-recursive` / `--ls-type` | `list -R/--recursive` / `--type all\|dirs\|files` | Same meaning; invalid values now exit `2` |
| `--progress` | `--progress` | Live single-line counter, rendered by the CLI rather than rclone's TTY bar |
| Legacy config files on disk | `saved_configs` table (SQLite) | `configs:save`/`configs:forget` + `wizard --config=` |
| ACL values `private/public-read/public-read-write/authenticated-read` | `private` **or** `public` | Application-level values mapped in `config/storage.php`, including the `acl` configured on the remote itself |

## Path Syntax

The legacy script took remote/bucket/path as three separate options. The new
CLI accepts one canonical path:

```text
<remote>:<bucket>/<path>
```

Examples:

```bash
# Before
./multi-remote-transfer.sh --source-remote do-spaces-nyc --source-bucket my-data \
    --source-path videos --operation copy \
    --dest-remote do-spaces-sgp --dest-bucket backup --dest-path movies

# After
storage copy do-spaces-nyc:my-data/videos/ do-spaces-sgp:backup/movies/
```

Local paths are used unchanged; `local:` may be used to disambiguate paths that
contain colons.

## Behavioural Notes

- **Directory semantics**: copying a prefix copies its *contents* into the
  destination, preserving relative structure (the legacy script behaved the
  same way; this is guaranteed by `TransferService`).
- **Move reliability**: `move`/`rename` only delete the source after the copy
  is verified at the destination. Partial operations exit with code 6 and
  report how many objects failed. Where a remote is involved this is one
  `rclone move`/`moveto` invocation, which performs the copy, the verification
  and the source deletion per object, in parallel and server-side where the
  backend allows it. Objects skipped because the destination already exists
  keep their source copy; a source prefix emptied by the move is removed.
- **Reported counts are measured**: rclone runs with `--use-json-log` and the
  `copied` / `skipped` / `failed` numbers come from its own final counters, so
  the summary describes what happened rather than what was expected to happen.
  Only `--dry-run` predicts from a listing.
- **Missing paths are errors, not no-ops**: a source, delete target, list path
  or retry ID that does not exist exits `3` (or `1` for `retry`). The legacy
  script's silent success on a mistyped path has no equivalent here. On object
  stores a non-existent prefix cannot be told apart from an empty one, so a
  remote prefix holding nothing is reported as not found too; an empty local
  directory still lists as empty and exits `0`.
- **Paths with spaces**: object keys may contain spaces
  (`"remote:bucket/My Reports/Q3 final.pdf"`); only the text before the first
  `:` is read as a remote name, so local paths containing a colon still need
  the `local:` prefix.
- **Default does not overwrite**: existing destination objects are kept unless
  `--overwrite` is passed.
- **Delete scope confirmation**: `delete` verifies the path exists, then shows
  the object count and total size before asking for confirmation; use `--force`
  non-interactively.
- **The default ACL differs**: the Bash script defaulted `ACL="private"` and
  passed `--s3-acl` on *every* transfer, so a run without `--acl public-read`
  wrote **private** objects even into a bucket whose remote was configured as
  public. The CLI instead uses the destination remote's own `acl` when neither
  `--acl` nor `STORAGE_DEFAULT_ACL` is given, so objects keep the visibility the
  remote was set up for. Set `STORAGE_DEFAULT_ACL=private` for the old
  behaviour. (This is also why the script never hit the failure below: an
  explicit, validated `--s3-acl` meant rclone never read the remote's `acl`
  key.)
- **ACLs on the remote are resolved, not passed through**: rclone sends a
  remote's configured `acl` verbatim, so a remote set up with an
  application-level value such as `acl = public` fails every server-side copy
  with `400 InvalidArgument` (S3 only accepts its canned ACL names). The CLI
  maps the destination remote's ACL through `config/storage.php` before
  transferring — `public` becomes `public-read`, keeping the intended
  visibility — and refuses up front, before touching any object, when a value
  maps to nothing valid.
- **Errors are grouped**: identical failures across objects and across rclone's
  retries collapse into one line with a count and an example object, instead of
  one line per attempt per object.
- **Credentials**: the new CLI never stores or logs credentials — they remain
  in the rclone config (`rclone config`).
- **Async jobs**: `--queue` on transfer commands dispatches
  `ProcessTransferJob`; with `QUEUE_CONNECTION=database` the job persists until
  `queue:work database` runs it. Partial/failed jobs land in `failed_jobs` and
  can be re-dispatched with `retry <id>` (equivalent to the legacy script's
  "retry failed transfers" behaviour).

## Exit Codes

The legacy script only distinguished success/failure. The new CLI exposes
granular exit codes (see README): `0` success, `1` failure, `2` invalid
arguments, `3` not found, `4` destination, `5` visibility, `6` partial.

Scripts that wrapped the Bash helper and only checked `$? -eq 0` keep working,
but can now distinguish a typo (`3`) from a genuine transfer failure (`1`) and
from a run where only some objects failed (`6`).

## Output Differences

The legacy script printed rclone's raw output. The new CLI renders its own
report: a badge header, aligned detail rows, and a result badge carrying the
counts and elapsed time. Points worth knowing when porting scripts:

- rclone's log prefixes (`2026/01/01 12:00:00 ERROR : …`) are stripped from
  error messages, and at most five failures are shown inline — the rest are in
  the log directory (`STORAGE_LOG_PATH`).
- `--progress` draws one live line that is erased on completion, and only on an
  interactive terminal. Piping or redirecting output produces clean, stable
  text suitable for logs and CI.
- "Nothing to do" is reported with an `INFO` badge, never a `DRY RUN` badge, so
  a real run that changed nothing cannot be mistaken for a simulation.