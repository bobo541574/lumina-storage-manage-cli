# Architecture

The CLI follows a strict layered design so that a new backend never requires
rewriting commands.

```text
Command (parse args, build StoragePath + TransferOptions)
    ↓
Service (assert source exists, choose driver, enforce directory semantics)
    ↓
StorageDriver contract
    ↓
RcloneStorageDriver / LocalStorageDriver
    ↓
rclone CLI / PHP filesystem / AWS CLI
    ↓
TransferResult → render → ExitCode
```

## Layers

### Commands (`app/Commands/`)

Parse arguments, ask questions, build `StoragePath` / `TransferOptions` values,
call services, and render results. No rclone logic, no business rules. Output
styling is centralized in two traits:

- `PresentsOutput` — badges, aligned detail rows, timing
- `HandlesStorageErrors` — shared options, error→exit-code mapping, transfer
  result rendering, live progress

### Services (`app/Services/`)

Own the application logic:

- `StorageService` — driver dispatch, list, exists, remote/bucket discovery
- `TransferService` — copy/move/download/upload semantics
- `VisibilityService` — visibility validation + delegation
- `RemoteManager` — rclone config CRUD (add/show/forget)

### Drivers (`app/Drivers/`)

Implement the `StorageDriver` contract and translate application semantics to
backend calls:

- `RcloneStorageDriver` — subprocess rclone (transfers) + AWS CLI (visibility)
- `LocalStorageDriver` — pure PHP, absolute paths, `chmod` for visibility

A third driver (e.g. a direct S3 SDK) can be added without touching commands.

### DTOs (`app/DTOs/`)

Immutable value objects: `StoragePath`, `StorageLocationType`, `TransferOptions`,
`TransferResult`, `TransferStatus`, `ListingResult`, `ListingEntry`.

### Support (`app/Support/`)

`RcloneProcess` (safe Symfony Process wrapper), `RcloneStats` (JSON-log parser),
`ProcessResult`, `StorageLogger`, `ExitCode`, `SizeFormatter`, `DurationFormatter`.

## Key design decisions

- **Transfer driver selection**: any pair with at least one remote end uses
  `RcloneStorageDriver`; local↔local uses `LocalStorageDriver`.
- **One rclone process per transfer** with a remote end — no per-object
  subprocess loops.
- **Counts are measured, not predicted**: `RcloneStats` reads rclone's final
  JSON counters. Only `--dry-run` estimates from a listing.
- **No-overwrite by default**: `--ignore-existing` unless `--overwrite`.
- **Queued jobs are self-contained**: `ProcessTransferJob` payloads carry
  concrete option values and never re-read config.
- **ACL resolved before any write** — see [ACL Handling](Acl-Handling).

## Service wiring

`AppServiceProvider` binds `StorageService`, `TransferService`,
`VisibilityService`, and `RcloneProcess` as singletons; the drivers are bound
(not singleton) so memoised state lasts one command.

## Related

- [Path Syntax](Path-Syntax)
- [Exit Codes](Exit-Codes)
- [Configuration](Configuration)