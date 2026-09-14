# Laravel Zero Storage CLI — Development Task

> **Status: implemented.** This document describes both the original requirements
> (sections 1–3, 6–30 on mandatory path/directory/transfer semantics) and the
> current implementation reference (section 4 structure, section 5 contract,
> sections 5.1–5.10 DTOs/support/services/commands/queue/wizard, sections
> 31–35 configuration/testing/process/exit codes). Where the original suggestion
> differs from what was built, the implementation reference sections are
> authoritative.

## Project Goal

Build a production-quality CLI application using **Laravel Zero** for managing files and objects across multiple storage backends.

The existing Bash script is provided as the legacy/reference implementation.

The legacy Bash script currently uses:

* `rclone` for storage/file transfers
* AWS S3 CLI for object ACL changes
* DigitalOcean Spaces / S3-compatible storage
* Local filesystem
* Saved configuration files
* Interactive CLI prompts
* Logging
* Dry-run mode
* Retry and parallel transfer configuration

The new application MUST NOT simply translate the Bash script line-by-line.

Instead, redesign it as a maintainable Laravel Zero CLI application with clear separation of concerns.

---

# 1. Legacy Behavior

The existing Bash script supports these operations:

* `copy`
* `move`
* `sync`
* `download`
* `upload`
* `list`
* `set-acl`

It also supports:

* multiple rclone remotes
* multiple buckets
* source path
* destination path
* ACL
* recursive operations
* dry-run
* verbose output
* retry count
* parallel transfer count
* saved configurations
* interactive mode

Preserve these existing capabilities unless there is a strong architectural reason to change them.

Do not silently remove existing behavior.

Before changing any behavior, document:

```text
Legacy behavior
New behavior
Reason for change
Migration impact
```

---

# 2. New Required Operations

The new CLI application must support:

### Basic

* List
* Download
* Upload
* Copy
* Move

### File/Object Management

* Rename
* Duplicate
* Copy To
* Move To
* Delete

### Access

* Visibility

Visibility should support at least:

* private
* public-read

If the underlying storage supports additional ACL modes, design the implementation so they can be added without changing the command layer.

### Additional useful operations

Design the architecture so these can be added later:

* mkdir
* info
* search
* bulk-delete
* bulk-copy
* bulk-move
* archive
* restore
* checksum

Do not implement every future feature now unless required by the architecture.

---

# 3. Important Architecture Rule

Use this architecture:

```text
Command
   ↓
Application Service
   ↓
Storage Driver Contract
   ↓
Storage Driver Implementation
   ↓
rclone / S3 API / Local Filesystem
```

Do NOT put rclone commands directly inside Command classes.

Do NOT put business logic inside Command classes.

Commands should primarily:

1. parse arguments/options
2. ask interactive questions when needed
3. construct DTO/request objects
4. call application services
5. render results/errors

---

# 4. Project Structure

```text
app/
├── Commands/
│   ├── Concerns/
│   │   ├── HandlesStorageErrors.php    — shared option defs, error mapping, result rendering
│   │   └── PresentsOutput.php          — terminal badges, detail rows, timing
│   ├── ListCommand.php                 — replaces Symfony's ListCommand (canonical "list")
│   ├── CopyCommand.php
│   ├── CopyToCommand.php               — alias of copy
│   ├── MoveCommand.php
│   ├── MoveToCommand.php               — alias of move
│   ├── RenameCommand.php
│   ├── DuplicateCommand.php
│   ├── DeleteCommand.php
│   ├── DownloadCommand.php
│   ├── UploadCommand.php
│   ├── VisibilityCommand.php
│   ├── WizardCommand.php               — interactive wizard (alias: interactive)
│   ├── RetryCommand.php                — re-dispatch failed queue jobs
│   ├── RemotesCommand.php              — list rclone remotes (alias: list-remotes)
│   ├── RemotesAddCommand.php           — add a new rclone remote
│   ├── RemotesShowCommand.php          — show one remote's config (redacted)
│   ├── RemotesForgetCommand.php        — delete a remote from rclone config
│   ├── ConfigsCommand.php              — list saved profiles (alias: list-configs)
│   ├── ConfigsSaveCommand.php          — save a transfer profile
│   └── ConfigsForgetCommand.php        — delete a saved profile
│
├── Contracts/
│   ├── StorageDriver.php               — application-level storage semantics
│   └── RemoteDiscovery.php             — remote/bucket discovery (for cache-testable services)
│
├── Drivers/
│   ├── RcloneStorageDriver.php         — rclone + AWS CLI subprocesses
│   └── LocalStorageDriver.php          — pure PHP, operates on absolute paths
│
├── Services/
│   ├── StorageService.php              — driver dispatch, list, exists, remotes/buckets
│   ├── TransferService.php             — copy/move/download/upload orchestration
│   ├── VisibilityService.php           — visibility/ACL validation + delegation
│   └── RemoteManager.php               — rclone config CRUD (add/show/forget)
│
├── DTOs/
│   ├── StoragePath.php                 — immutable value object, parses remote/local paths
│   ├── StorageLocationType.php         — enum: Remote | Local
│   ├── TransferOptions.php             — readonly DTO: transfers/retries/dryRun/overwrite/acl/…
│   ├── TransferResult.php              — readonly DTO: status/copied/skipped/failed/errors
│   ├── TransferStatus.php              — enum: Success | Partial | Failed
│   ├── ListingResult.php               — readonly DTO: entries + count/totalSize
│   └── ListingEntry.php                — readonly DTO: name/path/size/isDirectory/isFile
│
├── Exceptions/
│   ├── StorageException.php            — base (extends RuntimeException)
│   ├── PathParseException.php
│   ├── PathValidationException.php
│   ├── ObjectNotFoundException.php
│   ├── RemoteNotFoundException.php
│   ├── BucketNotFoundException.php
│   ├── TransferException.php
│   ├── VisibilityException.php
│   ├── DeleteException.php
│   └── RenameException.php
│
├── Jobs/
│   └── ProcessTransferJob.php          — queued transfer (dispatched by --queue)
│
├── Models/
│   └── SavedConfig.php                 — saved wizard/config profiles (saved_configs table)
│
├── Logging/
│   └── CreateStorageLogger.php         — Monolog RotatingFileHandler factory
│
├── Providers/
│   └── AppServiceProvider.php          — singleton/bind registrations
│
└── Support/
    ├── RcloneProcess.php               — safe Symfony Process wrapper (array args, env passthrough)
    ├── RcloneStats.php                 — JSON log parser (transfers/checks/errors/collapse)
    ├── ProcessResult.php               — readonly: exitCode/stdout/stderr
    ├── StorageLogger.php               — Monolog RotatingFileHandler on "storage" channel
    ├── ExitCode.php                    — constants: SUCCESS/FAILURE/INVALID/SOURCE_NOT_FOUND/…
    ├── SizeFormatter.php               — bytes → B/K/M/G/T/P
    └── DurationFormatter.php           — seconds → ms/s/m/h

config/
├── storage.php                         — driver, logging, defaults, cache, visibility mapping
├── commands.php                        — default command, hidden/removed framework commands
├── app.php                             — name, version, providers
├── database.php, queue.php, cache.php, logging.php — standard Laravel config
```

---

# 5. StorageDriver Contract

The `StorageDriver` interface represents **application-level storage semantics**, not rclone-specific semantics. All path parameters are validated `StoragePath` value objects. Backend-specific translation (e.g. rclone trailing-slash rules, S3 ACL naming) belongs in the implementation, never in the command or service layer.

```php
interface StorageDriver
{
    public function name(): string;

    /** Whether the configured backend is available (e.g. binary present). */
    public function isAvailable(): bool;

    /** List the contents of a path (directory/prefix). Directories and files
     *  can be filtered independently. */
    public function list(StoragePath $path, bool $recursive = false,
        bool $directories = true, bool $files = true): ListingResult;

    /** Whether an object/file/prefix exists at the given path. */
    public function exists(StoragePath $path): bool;

    /** Remote → Local fetch of a single object, prefix, or bucket. */
    public function download(StoragePath $source, StoragePath $destination,
        TransferOptions $options = new TransferOptions): TransferResult;

    /** Local → Remote push of a single object, directory, or bucket. */
    public function upload(StoragePath $source, StoragePath $destination,
        TransferOptions $options = new TransferOptions): TransferResult;

    /** Copy a single object/prefix from one location to another. */
    public function copy(StoragePath $source, StoragePath $destination,
        TransferOptions $options = new TransferOptions): TransferResult;

    /** Move a single object/prefix from one location to another. */
    public function move(StoragePath $source, StoragePath $destination,
        TransferOptions $options = new TransferOptions): TransferResult;

    /** Rename a single object/prefix within the same location. */
    public function rename(StoragePath $source, StoragePath $destination,
        TransferOptions $options = new TransferOptions): TransferResult;

    /** Delete an object or the contents of a prefix. */
    public function delete(StoragePath $path,
        TransferOptions $options = new TransferOptions): TransferResult;

    /** Set the visibility (ACL) of an object, or recursively of all objects
     *  under a prefix. */
    public function visibility(StoragePath $path, string $visibility,
        TransferOptions $options = new TransferOptions): TransferResult;
}
```

The companion `RemoteDiscovery` interface is implemented by `RcloneStorageDriver` and consumed by `StorageService` for cache-testable remote/bucket discovery:

```php
interface RemoteDiscovery
{
    public function remotes(): array;
    public function buckets(string $remote): array;
}
```

### Implementation Notes

* **`RcloneStorageDriver`** — subprocess-based (rclone for transfers, AWS CLI `s3api put-object-acl` for visibility). `exists()` must check for **non-empty** `lsf` output, not exit status (rclone exits 0 with empty stdout for non-existent keys). `list()` on a remote raises `ObjectNotFoundException` (exit 3) when the listing is empty and the path is not a bucket root.
* **`LocalStorageDriver`** — pure PHP, operates on absolute paths (cwd-relative for relative input). Visibility maps to `chmod` (755/644 for public, 700/600 for private).
* Both drivers are **not** singletons (the provider uses `bind`); memoised `isAvailable()`/`remotes()` last one command. Call `flushRemotes()` after changing the rclone config.

---

# 5.1 Data Transfer Objects

All DTOs live in `app/DTOs/` and are `final readonly` (or `final class` for mutable value objects).

### StoragePath (Immutable Value Object)

Parses and normalizes canonical storage paths. Private constructor; created via `fromString()`, `fromRemote()`, or `fromLocal()`.

```php
final class StoragePath
{
    public static function fromString(string $value): self;   // parse user input
    public static function fromRemote(string $remote, ?string $bucket, ?string $path): self;
    public static function fromLocal(string $path): self;

    public function type(): StorageLocationType;               // Remote | Local
    public function isRemote(): bool;
    public function isLocal(): bool;
    public function remote(): ?string;
    public function bucket(): ?string;
    public function path(): ?string;                           // null = bucket root
    public function isDirectory(): bool;                       // trailing slash
    public function isFile(): bool;
    public function isPrefix(): bool;                          // remote + directory + not bucket root
    public function isBucketRoot(): bool;                      // remote:bucket with no path
    public function filename(): ?string;                       // basename of path
    public function toRclonePath(): string;                    // "remote:bucket/path"
    public function toDisplayString(): string;                 // same as toRclonePath()
    public function asDirectory(): self;                       // return copy marked as directory
    public function child(string $segment): self;              // build child path
}
```

Parsing rules:
* `remote:bucket/path` → Remote type, splits on first `/` after `:`
* `/absolute/path` → Local type
* `./relative` or `~/path` → Local type (home expanded)
* `local:/path` → explicit Local type
* Trailing `/` → `isDirectory() = true`
* Empty string → throws `PathParseException`

### StorageLocationType

```php
enum StorageLocationType: string {
    case Remote = 'remote';
    case Local  = 'local';
}
```

### TransferOptions (Readonly DTO)

```php
final readonly class TransferOptions
{
    public function __construct(
        public int     $transfers = 8,
        public int     $retries   = 3,
        public bool    $dryRun    = false,
        public bool    $verbose   = false,
        public bool    $overwrite = false,
        public bool    $recursive = false,
        public bool    $progress  = false,
        public ?string $acl       = null,   // null = inherit remote's own ACL
    ) {}
}
```

### TransferResult (Readonly DTO)

```php
final readonly class TransferResult
{
    public function __construct(
        public TransferStatus  $status,
        public int             $copied = 0,
        public int             $skipped = 0,
        public int             $failed = 0,
        public array           $errors = [],     // array<int, string>
        public ?StoragePath    $source = null,
        public ?StoragePath    $destination = null,
    ) {}

    public static function success(...): self;
    public function total(): int;                 // copied + skipped + failed
}
```

### TransferStatus (Enum)

```php
enum TransferStatus: string {
    case Success = 'success';
    case Partial = 'partial';
    case Failed  = 'failed';
}
```

### ListingResult / ListingEntry

```php
final readonly class ListingResult
{
    public function __construct(public array $entries = []) {}  // array<ListingEntry>
    public function count(): int;
    public function totalSize(): int;
}

final readonly class ListingEntry
{
    public function __construct(
        public string $name,
        public string $path,
        public int    $size = 0,
        public bool   $isDirectory = false,
        public bool   $isFile = false,
    ) {}
}
```

---

# 5.2 Support Classes

All in `app/Support/`.

### RcloneProcess

Safe process execution wrapper. **Intentionally not final** — tests extend it with a stub.

```php
class RcloneProcess
{
    public function __construct(string $binary = 'rclone', int $timeout = 3600);

    public function run(array $arguments, ?callable $onOutput = null): ProcessResult;
}
```

Passes `env: getenv()` explicitly (Symfony Process only inherits variables in both `getenv()` and `$_SERVER`; variables set via `putenv()` alone like `RCLONE_CONFIG` are silently dropped otherwise).

### RcloneStats (Readonly DTO)

Parses the JSON log emitted by rclone on stderr (launched with `--use-json-log --stats=1s --stats-one-line --stats-log-level NOTICE`).

```php
final readonly class RcloneStats
{
    public function __construct(
        public bool   $present = false,
        public int    $transfers = 0,
        public int    $checks = 0,
        public int    $errors = 0,
        public int    $deletes = 0,
        public int    $bytes = 0,
        public int    $totalBytes = 0,
        public float  $elapsed = 0.0,
        public array  $errorMessages = [],  // collapsed/deduped
    ) {}

    public static function parse(string $output): self;
    public function progress(): ?float;     // 0.0–1.0 or null
}
```

Error collapsing: `RequestID`/`HostID` stripped, `Attempt N/M failed` summaries dropped, identical failures grouped with count and one example key. At most 5 errors shown inline; rest in log.

### ProcessResult (Readonly)

```php
final readonly class ProcessResult
{
    public function __construct(
        public int    $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function successful(): bool;
    public function failed(): bool;
    public function combine(): string;     // stdout + stderr
}
```

### StorageLogger

Monolog `RotatingFileHandler` on the custom `storage` channel. Per-directory loggers are cached.

```php
final class StorageLogger
{
    public function directory(string $subdir): self;
    public function log(string $level, string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public static function expandHome(string $path): string;
}
```

Logs operation metadata only — never credentials, access keys, secret keys, or tokens.

### ExitCode

```php
final class ExitCode
{
    public const SUCCESS          = 0;
    public const FAILURE          = 1;
    public const INVALID          = 2;   // path parse / validation errors
    public const SOURCE_NOT_FOUND = 3;   // object/remote/bucket not found
    public const DESTINATION      = 4;
    public const VISIBILITY       = 5;
    public const PARTIAL          = 6;   // some objects failed
}
```

### SizeFormatter / DurationFormatter

```php
final class SizeFormatter    { public static function human(int $bytes): string; }    // B/K/M/G/T/P
final class DurationFormatter { public static function human(float $seconds): string; } // ms/s/m/h
```

---

# 5.3 Services

### StorageService (Singleton)

Driver dispatch, listing, existence checks, and cached remote/bucket discovery.

```php
class StorageService  // not final — tests use Mockery
{
    public function __construct(
        RemoteDiscovery       $rclone,
        LocalStorageDriver    $local,
        ?Repository           $cache = null,
        int                   $cacheTtl = 300,
    );

    public function driverFor(StoragePath $path): StorageDriver;
    public function list(StoragePath $path, bool $recursive = false, string $type = 'all'): ListingResult;
    public function exists(StoragePath $path): bool;
    public function remotes(): array;                     // cached
    public function buckets(string $remote): array;       // cached
}
```

Cache: optional `Repository` with configurable TTL (`storage.cache.ttl`, 0 disables). `driverFor()` dispatches by `StorageLocationType`.

### TransferService (Singleton)

Orchestrates transfer semantics. Not final (jobs mock it).

```php
class TransferService
{
    public function __construct(StorageService $storage, RcloneStorageDriver $rclone, LocalStorageDriver $local);

    public function copy(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult;
    public function download(...): TransferResult;    // delegates to copy
    public function upload(...): TransferResult;      // delegates to copy
    public function move(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult;
    public function reportProgress(?callable $handler): void;
    public function transferDriver(StoragePath $source, StoragePath $destination): StorageDriver;
}
```

Key semantics:
* Directory copy = copy CONTENTS into destination prefix, preserving relative structure. Source dir name is NOT appended.
* `transferDriver()`: both local → `LocalStorageDriver`, otherwise → `RcloneStorageDriver`.
* `move()` with remote end: delegates to `RcloneStorageDriver::move()` (single rclone process with `move`/`moveto` + `--delete-empty-src-dirs`). The per-object loop in `TransferService::move()` is only for **local↔local**.
* `--dry-run` short-circuits via `expectedCounts()` before any write/mkdir.

### VisibilityService (Singleton)

```php
class VisibilityService  // not final
{
    public function __construct(StorageService $storage);

    public function apply(StoragePath $path, string $visibility, TransferOptions $options = new TransferOptions): TransferResult;
}
```

Validates visibility against the config map plus `private`/`public`/`public-read`/`public-read-write`/`authenticated-read`. Throws `PathValidationException` for disallowed values.

### RemoteManager (Final)

```php
final class RemoteManager
{
    public function list(): array;
    public function add(string $name, string $type, array $config): void;
    public function show(string $name): string;
    public function forget(string $name): void;
}
```

Shells out to `rclone listremotes` / `config create` / `config show` / `config delete`. Secret-looking keys (`pass|secret|token|access_key`) are redacted in `show()` output. Credentials are never stored or logged by the application.

---

# 5.4 Command Traits

### PresentsOutput (`app/Commands/Concerns/PresentsOutput.php`)

Single source of CLI styling. Used by all commands (directly or via `HandlesStorageErrors`).

```php
trait PresentsOutput
{
    protected function renderOperationHeader(string $title, bool $dryRun = false): void;
    protected function renderDetail(string $label, string $value): void;   // label padded to 13 cols
    protected function renderSeparator(int $width = 56): void;
    protected function renderSuccess(string $message): void;               // green badge
    protected function renderFailed(string $message): void;                // red badge
    protected function renderPartial(string $message): void;               // yellow badge
    protected function renderDryRun(string $message): void;                // yellow badge
    protected function renderInfo(string $message): void;                  // blue badge
    protected function renderError(string $message): void;                 // red ✖, only error style
    protected function renderHint(string $message): void;                  // gray
    protected function elapsed(): ?string;                                 // DurationFormatter
}
```

### HandlesStorageErrors (`app/Commands/Concerns/HandlesStorageErrors.php`)

Used by all transfer/delete/visibility commands. Uses `PresentsOutput`.

```php
trait HandlesStorageErrors
{
    use PresentsOutput;

    protected function addTransferOptions(): void;
    protected function addQueueOption(): void;
    protected function transferOptions(): TransferOptions;
    protected function maybeQueueOperation(string $operation, StoragePath $source, StoragePath $destination, TransferOptions $options, array $extra = []): ?int;
    protected function exitCodeFor(\Throwable $exception): int;
    protected function reportException(\Throwable $exception): int;
    protected function renderTransfer(TransferResult $result, string $pastVerb, string $pluralVerb, bool $dryRun = false): int;
    protected function renderErrorList(array $errors, int $limit = 5): void;
    protected function trackProgress(TransferService $transfers, TransferOptions $options): void;
    protected function clearProgress(): void;
    protected function describeLocations(StoragePath $source, StoragePath $destination, string $operation, bool $dryRun): void;
}
```

Transfer option defaults come from `config('storage.defaults')` and are set as Symfony option defaults in `addTransferOptions()` — the queued payload and option resolution always carry concrete values (or explicit `null` for ACL = "inherit remote's own ACL").

---

# 5.5 All Commands

| Command | Name / Aliases | Description |
|---------|----------------|-------------|
| `ListCommand` | `list` | List objects/dirs. `--type all/dirs/files`, `--recursive` (`-R`), `--size` (each directory's recursive total; one extra `lsf -R` pass when not already recursive), `--sort-dir`/`--sort-file` (asc/desc/size/size-desc). SUMMARY line with counts + total file bytes (directory sizes are not double-counted). |
| `CopyCommand` | `copy` | Non-destructive copy. `--transfers/--retries/--dry-run/--overwrite/--progress/--acl/--queue`. |
| `CopyToCommand` | `copy-to` | Alias of copy (queues as `'copy'`). |
| `MoveCommand` | `move` | copy → verify → delete source. Same options as copy. |
| `MoveToCommand` | `move-to` | Alias of move (queues as `'move'`). |
| `RenameCommand` | `rename` | Rename/relocate. Delegates to `TransferService::move()`. |
| `DuplicateCommand` | `duplicate` | Non-destructive copy (source preserved). Delegates to `TransferService::copy()`. |
| `DeleteCommand` | `delete` | Delete object/prefix. Confirms unless `--force`/`--dry-run`. Shows scope (count + size). |
| `DownloadCommand` | `download` | Remote → local. Same options as copy. |
| `UploadCommand` | `upload` | Local → remote. Same options as copy. |
| `VisibilityCommand` | `visibility` | Set object/prefix visibility. `--dry-run/--verbose/--recursive`. |
| `WizardCommand` | `wizard` / `interactive` | Interactive wizard. `--config` (preload profile), `--save-as` (store profile). |
| `RetryCommand` | `retry` | Re-dispatch failed queue jobs by ID or `all`. Exits 1 if nothing matched. |
| `RemotesCommand` | `remotes` / `list-remotes` | Table of configured rclone remotes. |
| `RemotesAddCommand` | `remotes:add` | Add rclone remote. Validates name/type + KEY=VALUE. |
| `RemotesShowCommand` | `remotes:show` | Print redacted remote config. |
| `RemotesForgetCommand` | `remotes:forget` | Delete rclone remote. Confirms unless `--force`. |
| `ConfigsCommand` | `configs` / `list-configs` | Table of saved profiles. |
| `ConfigsSaveCommand` | `configs:save` | Save a transfer profile. |
| `ConfigsForgetCommand` | `configs:forget` | Delete a saved profile by name. |

---

# 5.6 Queue System

### ProcessTransferJob (`app/Jobs/ProcessTransferJob.php`)

Dispatched by `--queue` on transfer commands. `ShouldQueue`, `tries = 3`.

```php
class ProcessTransferJob implements ShouldQueue
{
    public function __construct(
        public readonly string  $operation,     // copy|move|download|upload|delete|visibility
        public readonly string  $source,        // rclone path string
        public readonly ?string $destination,
        public readonly array   $options,       // TransferOptions keys
    ) {}

    public function handle(): void;
    // Rebuilds TransferOptions from payload only — never reads config().
}
```

Operation mapping: `copy`/`duplicate` → `TransferService::copy()`, `move`/`rename` → `move()`, `download` → `download()`, `upload` → `upload()`, `delete` → driver `delete()`, `visibility` → `VisibilityService::apply()`.

When `result->failed > 0`, the job throws so it lands in `failed_jobs` and can be re-dispatched via `retry`.

Queue flow: `QUEUE_CONNECTION=database <entry> copy <src> <dst> --queue` → `<entry> queue:work database --stop-when-empty --tries=1` → `<entry> retry <id>` → `queue:work` again. Under the default `sync` connection, `--queue` runs inline (still prints "Queued").

---

# 5.7 Wizard Flow

`WizardCommand` drives an interactive prompt sequence:

1. **Load profile** (optional) — `--config=<name>` loads a `SavedConfig` row; missing → exit 3.
2. **Select storage** — remotes from `StorageService::remotes()` plus `'local'`. Default from profile if available.
3. **Root selection:**
   - Local → `Local working directory` (default: profile source or `getcwd()`/`HOME`).
   - Remote → `Select bucket` (choice from `buckets()` with `<enter bucket manually>` sentinel); empty or manual → `Bucket name` (ask). Root = `storage:bucket`.
4. **Select operation** — List, Download, Upload, Copy, Move, Rename, Duplicate, Copy To, Move To, Visibility, Delete.
5. **Operation-specific prompts:**
   - List: `Source path` (default root).
   - Transfers: `Source path` → `Destination path` → `Overwrite?` → `Dry run?` → `Show live progress?` → `Plan: ...` → `Run operation?`.
   - Visibility: `Object path` → `Visibility` (private/public).
   - Delete: `Path to delete` → delete command's own confirmation.
6. **Save profile** (optional) — `--save-as=<name>` upserts a `SavedConfig` row.

Paths resolved via `resolveAgainstRoot()` — absolute `/` or `remote:bucket/...` pass through; otherwise root is prepended. Delegates via `$this->call(<command>, [...])`.

---

# 5.8 ACL Handling

ACL precedence: `--acl` CLI option → `storage.defaults.acl` (`STORAGE_DEFAULT_ACL`) → destination remote's own `acl` config → rclone's default.

`RcloneStorageDriver::aclFlags()`:
1. If `TransferOptions::$acl` is set, map through `config('storage.visibility')` → `--acl=<canned>` or `--s3-acl=<explicit>`.
2. If absent, read the destination remote's own `acl` key (memoised via `rclone config show`).
3. Canned S3 ACLs passed as `--acl=<value>`. Non-canned values mapped into `--s3-acl=`.
4. Unmappable value → `TransferException` **before** anything is transferred.

Only the destination remote is consulted — a local destination performs no S3 write.

---

# 5.9 Runtime Quirks

* **No-overwrite by default.** All transfer commands use `--ignore-existing` unless `--overwrite`.
* **Any transfer with a remote end runs as ONE rclone process.** Never reintroduce a per-object loop — it spawned ~10 subprocesses per object.
* **Counts come from rclone, never from a prediction.** `RcloneStats::parse()` reads the last `stats` object off stderr. Only `--dry-run` uses `TransferService::expectedCounts()`.
* **`exists()` checks non-empty output, not exit status.** `rclone lsf` exits 0 with empty stdout for non-existent keys.
* **`list()` raises `ObjectNotFoundException`** (exit 3) when the listing is empty and not a bucket root.
* **Nothing to do renders an INFO badge**, never DRY RUN.
* **`--progress` does NOT pass `--progress` to rclone.** Commands call `trackProgress()` which uses `\r` line redrawing from parsed `RcloneStats`.
* **Test seam:** `FakeRcloneProcess` extends `RcloneProcess` to stub subprocess calls.
* **Subprocess env inheritance:** `RcloneProcess::run()` passes `env: getenv()` explicitly.
* **Memoised remotes:** `isAvailable()`/`remotes()` last one command instance; call `flushRemotes()` after config changes.

---

# 5.10 Build & Entry Point

```bash
# Development
php storage-manage-cli <command>

# Build phar
php storage-manage-cli app:build storage
# → builds/storage
```

Default command is `list`. Framework `ListCommand` + `SummaryCommand` are removed via `config/commands.php`.

---

# 6. Canonical Storage Path Syntax

Storage path syntax MUST be explicitly defined and used consistently throughout the entire application.

Do not invent different path formats for different commands.

The application must distinguish clearly between:

1. Local filesystem paths
2. Remote storage paths
3. Bucket paths
4. Object/file paths
5. Directory/prefix paths

---

## 6.1 Canonical Remote Storage Syntax

The canonical public CLI syntax for remote storage is:

```text
<remote>:<bucket>/<path>
```

Examples:

```text
do-spaces-nyc:my-data/report.pdf
do-spaces-nyc:my-data/documents/report.pdf
do-spaces-sgp:backup/videos/2026/movie.mp4
do-spaces-ams:archive/
```

Where:

```text
remote = rclone remote name
bucket = storage bucket
path   = object key / directory prefix
```

Example:

```text
do-spaces-nyc:my-data/videos/2026/movie.mp4
│               │       │
│               │       └── object path
│               └────────── bucket
└────────────────────────── rclone remote
```

This syntax MUST be used consistently in:

* command arguments
* command output
* logs
* errors
* DTOs
* services
* tests
* documentation
* examples

---

## 6.2 Remote Bucket Root

A remote bucket root is:

```text
<remote>:<bucket>
```

Example:

```text
do-spaces-nyc:my-data
```

This represents the root of the bucket.

Accepting:

```text
do-spaces-nyc:my-data/
```

is allowed as input, but normalize it internally to:

```text
do-spaces-nyc:my-data
```

---

## 6.3 Remote Directory / Prefix

A remote directory/prefix is represented as:

```text
<remote>:<bucket>/<prefix>/
```

Example:

```text
do-spaces-nyc:my-data/documents/
```

Object storage may not have real directories.

Therefore, treat this as a **logical prefix** unless the backend explicitly supports directory objects.

---

## 6.4 Remote File/Object

A file/object uses:

```text
<remote>:<bucket>/<object-key>
```

Example:

```text
do-spaces-nyc:my-data/documents/report.pdf
```

which represents:

```text
remote   = do-spaces-nyc
bucket   = my-data
object   = documents/report.pdf
filename = report.pdf
```

---

## 6.5 Local Filesystem

Local paths MUST use normal filesystem syntax.

Examples:

```text
/home/user/downloads/report.pdf
/home/user/backups/
./downloads/report.pdf
../backup/file.zip
```

Do not interpret:

```text
/home/user/file.pdf
```

as a remote storage path.

---

## 6.6 Explicit Local Prefix

If needed for ambiguous situations, support:

```text
local:/home/user/file.pdf
```

as an explicit local path representation.

If implemented, `local:` must be handled by `LocalStorageDriver`.

Do not pass `local:` to rclone unless explicitly supported by the implementation.

---

# 7. StoragePath Value Object

Do not pass raw storage path strings throughout the application.

Create an immutable value object such as:

```php
StoragePath
```

It should parse and normalize paths.

The object should be able to represent:

```text
location type
remote
bucket
path
filename
directory/prefix status
```

Example:

```text
do-spaces-nyc:my-data/documents/report.pdf
```

should parse into:

```text
type     = remote
remote   = do-spaces-nyc
bucket   = my-data
path     = documents/report.pdf
filename = report.pdf
```

Example:

```text
do-spaces-nyc:my-data/documents/
```

should represent:

```text
type       = remote
remote     = do-spaces-nyc
bucket     = my-data
path       = documents/
is_prefix  = true
```

Example:

```text
/home/user/report.pdf
```

should represent:

```text
type     = local
path     = /home/user/report.pdf
filename = report.pdf
```

The value object should provide conversion methods where appropriate, for example:

```php
$storagePath->toRclonePath();
```

Do not duplicate:

```php
"{$remote}:{$bucket}/{$path}"
```

throughout the codebase.

---

# 8. Path Validation and Normalization

Create a single path parsing/normalization layer.

It should handle:

* remote syntax
* bucket root
* object path
* directory/prefix
* trailing `/`
* duplicate `/`
* local absolute paths
* local relative paths
* spaces
* special characters
* filename extraction

Be conservative with normalization.

Do NOT blindly normalize object keys in a way that changes their meaning.

Object keys may legally contain characters that look path-like.

Validate source and destination independently.

Do not silently guess a missing remote.

For example:

```text
my-data/documents/report.pdf
```

is NOT a complete remote storage path unless a command explicitly provides a default remote.

---

# 9. Directory / Prefix Semantics — Mandatory

Directory semantics MUST be explicit.

For object storage, a "directory" is a logical prefix.

The application must distinguish between:

```text
Object
Prefix / Directory
Bucket Root
```

A trailing `/` in CLI input SHOULD be treated as an explicit indication that the user intends a directory/prefix.

---

## 9.1 Core Rule

When copying a directory/prefix:

> Copy the CONTENTS of the source prefix into the destination prefix while preserving the source's relative structure.

Example source:

```text
do-spaces-nyc:my-data/documents/
├── report.pdf
├── invoice.pdf
└── 2026/
    └── january.pdf
```

Command:

```bash
storage copy \
    do-spaces-nyc:my-data/documents/ \
    do-spaces-sgp:backup/documents/
```

Expected result:

```text
do-spaces-sgp:backup/documents/
├── report.pdf
├── invoice.pdf
└── 2026/
    └── january.pdf
```

NOT:

```text
do-spaces-sgp:backup/documents/documents/
```

and NOT:

```text
do-spaces-sgp:backup/documents/2026/...
```

without preserving the actual relative structure.

---

## 9.2 Directory Copy Algorithm

Conceptually:

```text
SOURCE PREFIX
      │
      ▼
Enumerate source objects
      │
      ▼
Calculate relative object path
      │
      ▼
Append relative path to destination prefix
      │
      ▼
Copy object
```

Example:

```text
source prefix:
    documents/

source object:
    documents/2026/january/report.pdf

relative path:
    2026/january/report.pdf

destination prefix:
    backup/documents/

result:
    backup/documents/2026/january/report.pdf
```

The source directory name MUST NOT automatically be appended to the destination.

---

## 9.3 Directory-to-Directory

Given:

```text
source      = do-spaces-nyc:my-data/documents/
destination = do-spaces-sgp:backup/archive/
```

and:

```text
documents/report.pdf
documents/2026/january.pdf
```

the result MUST be:

```text
backup/archive/report.pdf
backup/archive/2026/january.pdf
```

NOT:

```text
backup/archive/documents/report.pdf
```

If the user wants that result, they must explicitly specify:

```text
do-spaces-sgp:backup/archive/documents/
```

as the destination.

---

## 9.4 Single File/Object Copy

For a single object:

```bash
storage copy \
    do-spaces-nyc:my-data/report.pdf \
    do-spaces-sgp:backup/report.pdf
```

the result is exactly:

```text
do-spaces-sgp:backup/report.pdf
```

No directory level should be added automatically.

---

## 9.5 File-to-Directory

If the destination is explicitly a directory/prefix:

```bash
storage copy \
    do-spaces-nyc:my-data/report.pdf \
    do-spaces-sgp:backup/documents/
```

the destination object is:

```text
do-spaces-sgp:backup/documents/report.pdf
```

The source filename is preserved.

---

## 9.6 Directory-to-Existing-Directory

If the destination prefix already exists, merge the source contents into it.

Example destination:

```text
archive/
└── old.pdf
```

Source:

```text
documents/
├── a.pdf
└── b.pdf
```

After copy:

```text
archive/
├── old.pdf
├── a.pdf
└── b.pdf
```

The existing destination contents must not be deleted.

---

## 9.7 Directory Recursive Behavior

Directory operations are inherently recursive.

Therefore:

```bash
storage copy \
    do-spaces-nyc:my-data/documents/ \
    do-spaces-sgp:backup/documents/
```

must include nested objects:

```text
documents/report.pdf
documents/2026/january/report.pdf
documents/2026/february/report.pdf
```

Do not require a separate `--recursive` option for a directory copy.

`--recursive` may exist for backward compatibility if the legacy script exposes it, but directory semantics must remain recursive.

---

## 9.8 Empty Directory

Object storage generally does not require a physical directory object.

If the source prefix contains no objects:

```text
No objects found under source prefix.

Nothing copied.
```

Do not invent a directory marker unless the selected backend explicitly requires it.

---

# 10. Directory Move

Directory move follows the same path semantics as directory copy.

Example:

```bash
storage move \
    do-spaces-nyc:my-data/documents/ \
    do-spaces-sgp:archive/documents/
```

Conceptually:

```text
COPY each source object
        ↓
VERIFY copied object
        ↓
DELETE corresponding source object
```

Only successfully copied and verified objects should be deleted from the source.

If some objects fail:

```text
Copied: 98
Deleted from source: 98
Failed: 2
```

the final status MUST be:

```text
PARTIAL
```

The failed source objects must remain intact.

---

# 11. Directory Rename

Directory rename is prefix rename.

Example:

```bash
storage rename \
    do-spaces-nyc:my-data/old-name/ \
    do-spaces-nyc:my-data/new-name/
```

means:

```text
old-name/*
      ↓
new-name/*
```

followed by deletion of successfully copied source objects.

Do not assume object storage provides a native directory rename.

If the backend provides a safer native implementation, the driver may use it.

---

# 12. Directory Duplicate

Directory duplicate MUST preserve the source.

Example:

```bash
storage duplicate \
    do-spaces-nyc:my-data/documents/ \
    do-spaces-nyc:my-data/documents-copy/
```

Result:

```text
documents/*
documents-copy/*
```

The source remains untouched.

---

# 13. Directory Delete

Deleting a directory/prefix means deleting all objects under that prefix.

Example:

```bash
storage delete \
    do-spaces-nyc:my-data/documents/
```

means:

```text
delete all objects under:

my-data/documents/
```

Interactive mode MUST show the deletion scope.

Example:

```text
Delete prefix:

do-spaces-nyc:my-data/documents/

Objects: 1,284
Total size: 84.3 GB

Continue?
```

If enumeration is unavailable, do not invent a count.

Use:

```text
Objects: unknown
```

or equivalent.

Recursive deletion requires explicit confirmation unless:

```bash
--force
```

is provided.

---

# 14. Directory Copy Overwrite Policy

Directory copy MUST NOT silently overwrite existing objects by default.

Default:

```text
existing destination object
        ↓
conflict
        ↓
do not overwrite
```

Support an explicit option such as:

```bash
--overwrite
```

when overwriting is intended.

The application must document exactly how conflicts are handled.

If rclone's default behavior differs from the application's public contract, configure the `RcloneStorageDriver` accordingly.

---

# 15. Directory Conflict Reporting

For recursive operations, report aggregate results.

Example:

```text
Copied:   98
Skipped:   4
Failed:    1
```

Verbose mode may show:

```text
[SKIP] Already exists:
do-spaces-sgp:backup/documents/report.pdf

[COPY]
do-spaces-nyc:my-data/documents/invoice.pdf
→
do-spaces-sgp:backup/documents/invoice.pdf
```

Recursive operations should support:

```text
SUCCESS
PARTIAL
FAILED
```

Do not reduce a partially completed operation to a misleading success status.

---

# 16. Cross-Storage Directory Operations

The same directory semantics MUST work for:

```text
Remote → Remote
Remote → Local
Local → Remote
Local → Local
```

Example:

```bash
storage copy \
    do-spaces-nyc:my-data/documents/ \
    /home/user/backup/
```

must result in:

```text
/home/user/backup/report.pdf
/home/user/backup/2026/january.pdf
```

NOT:

```text
/home/user/backup/documents/report.pdf
```

unless the user explicitly specifies:

```text
/home/user/backup/documents/
```

as the destination.

Likewise:

```bash
storage copy \
    /home/user/documents/ \
    do-spaces-sgp:backup/documents/
```

must preserve the contents and relative structure.

Commands MUST NOT contain special cases for these combinations.

---

# 17. Rclone Driver Semantics

The application-level path and directory semantics are authoritative.

Do NOT simply pass arbitrary user paths to:

```text
rclone copy <source> <destination>
```

and assume that rclone's behavior exactly matches the application's contract.

The `RcloneStorageDriver` is responsible for translating:

```text
Application semantics
        ↓
Validated StoragePath
        ↓
Rclone arguments
        ↓
Actual storage result
```

The driver must account for:

* trailing slash behavior
* directory/prefix behavior
* file-to-directory behavior
* recursive behavior
* overwrite behavior
* filtering
* remote-to-remote transfers
* remote-to-local transfers
* local-to-remote transfers

Rclone-specific path rules MUST remain inside the driver.

The Command and Application Service layers must not contain rclone-specific path manipulation.

---

# 18. Rename Semantics

For object storage, rename is generally not a true filesystem rename.

Treat rename as:

```text
COPY source → destination
        ↓
VERIFY destination
        ↓
DELETE source
```

unless the selected backend provides a safer native rename operation.

Failure example:

```text
COPY succeeds
VERIFY succeeds
DELETE fails
```

must produce:

```text
Rename partially completed.
Destination exists.
Source could not be deleted.
```

Never silently report success.

This applies to both files and directory/prefix renames.

---

# 19. Duplicate Semantics

Duplicate always preserves the source.

For a file:

```text
report.pdf
    ↓
report-copy.pdf
```

For a directory:

```text
documents/
    ↓
documents-copy/
```

Duplicate is conceptually a copy operation without source deletion.

Do not overwrite automatically unless explicitly requested.

---

# 20. Copy To

`copy-to` MUST use exactly the same path syntax and semantics as `copy`.

Example:

```bash
storage copy-to \
    do-spaces-nyc:my-data/videos/movie.mp4 \
    do-spaces-sgp:backup/movies/movie.mp4
```

Directory example:

```bash
storage copy-to \
    do-spaces-nyc:my-data/videos/ \
    do-spaces-sgp:backup/movies/
```

must copy the contents of `videos/` into `movies/` while preserving relative paths.

The source and destination may belong to different remotes/buckets.

Support:

```text
Remote A → Remote B
Remote A → Local
Local → Remote B
Local → Local
```

If `copy-to` is semantically identical to `copy`, it may be implemented as an alias, but this should be documented.

---

# 21. Move To

`move-to` MUST use the same path semantics as `move`.

For cross-storage moves:

```text
COPY
 ↓
VERIFY
 ↓
DELETE SOURCE
```

Reliability is more important than minimizing API calls.

Provide clear progress and partial-operation reporting.

---

# 22. Delete

Delete must support both objects and prefixes.

Single object:

```bash
storage delete \
    do-spaces-nyc:my-data/report.pdf
```

Prefix:

```bash
storage delete \
    do-spaces-nyc:my-data/documents/
```

Interactive deletion requires confirmation.

Non-interactive deletion requires:

```bash
--force
```

for destructive operations where confirmation cannot be performed.

Support:

```bash
--dry-run
```

for delete.

---

# 23. Visibility

Expose:

```bash
storage visibility \
    do-spaces-nyc:my-data/report.pdf \
    public
```

or:

```bash
storage visibility \
    do-spaces-nyc:my-data/report.pdf \
    private
```

Map:

```text
public
private
```

to backend-specific behavior.

Do not leak S3-specific ACL details into the Command layer.

Architecture:

```text
Command
   ↓
VisibilityService
   ↓
StorageDriver
   ↓
S3 ACL / rclone / provider API
```

For directory/prefix visibility, explicitly determine whether the operation applies recursively to all objects.

Do not silently assume recursive ACL changes if the backend does not support them.

---

# 24. Interactive Mode

The CLI should provide a useful interactive experience.

Example:

```text
Storage Manager

? Select source:
❯ do-spaces-nyc
  do-spaces-sgp
  do-spaces-ams
  local

? Select bucket:
❯ my-data
  backup
  documents

? Select operation:
❯ List
  Download
  Upload
  Copy
  Move
  Rename
  Duplicate
  Copy To
  Move To
  Visibility
  Delete
```

Use Laravel Zero / Symfony Console capabilities.

Do not manually implement fragile Bash-style prompt logic.

Interactive selection should eventually produce the same validated `StoragePath` objects used by non-interactive commands.

---

# 25. Non-Interactive CLI

Everything important must work from command arguments.

## List

```bash
storage list do-spaces-nyc:my-data/videos/
```

## Download

```bash
storage download \
    do-spaces-nyc:my-data/report.pdf \
    /home/user/downloads/report.pdf
```

## Upload

```bash
storage upload \
    /home/user/report.pdf \
    do-spaces-nyc:my-data/report.pdf
```

## Copy

```bash
storage copy \
    do-spaces-nyc:my-data/report.pdf \
    do-spaces-sgp:backup/report.pdf
```

## Move

```bash
storage move \
    do-spaces-nyc:my-data/report.pdf \
    do-spaces-sgp:backup/report.pdf
```

## Rename

```bash
storage rename \
    do-spaces-nyc:my-data/report.pdf \
    do-spaces-nyc:my-data/report-final.pdf
```

## Duplicate

```bash
storage duplicate \
    do-spaces-nyc:my-data/report.pdf \
    do-spaces-nyc:my-data/report-copy.pdf
```

## Copy To

```bash
storage copy-to \
    do-spaces-nyc:my-data/videos/ \
    do-spaces-sgp:backup/movies/
```

## Move To

```bash
storage move-to \
    do-spaces-nyc:my-data/videos/ \
    do-spaces-sgp:archive/videos/
```

## Visibility

```bash
storage visibility \
    do-spaces-nyc:my-data/report.pdf \
    public
```

## Delete

```bash
storage delete \
    do-spaces-nyc:my-data/report.pdf
```

All examples MUST use:

```text
<remote>:<bucket>/<path>
```

Never use inconsistent syntax such as:

```text
do-spaces-nyc/my-data/report.pdf
```

---

# 26. Download / Upload Directory Semantics

Download and upload must also follow the same directory content rules.

Directory download:

```bash
storage download \
    do-spaces-nyc:my-data/documents/ \
    /home/user/backup/documents/
```

must produce:

```text
/home/user/backup/documents/report.pdf
/home/user/backup/documents/2026/january.pdf
```

not:

```text
/home/user/backup/documents/documents/report.pdf
```

Directory upload:

```bash
storage upload \
    /home/user/documents/ \
    do-spaces-nyc:my-data/documents/
```

must produce:

```text
my-data/documents/report.pdf
my-data/documents/2026/january.pdf
```

preserving the relative directory structure.

---

# 27. Error Handling

Create meaningful application exceptions.

Examples:

```text
StorageException
RemoteNotFoundException
BucketNotFoundException
ObjectNotFoundException
TransferException
VisibilityException
DeleteException
RenameException
PathParseException
PathValidationException
```

Do not expose raw shell errors as the primary user experience.

Instead:

```text
[ERROR] Source object not found:

do-spaces-nyc:my-data/report.pdf
```

With:

```bash
--verbose
```

the underlying diagnostic information may be shown.

For partial operations:

```text
[PARTIAL] Operation completed with errors.

Copied: 98
Failed: 2
```

---

# 28. Logging

Preserve the legacy logging concept.

Use application logs such as:

```text
~/.config/storage-cli/logs/
```

Log:

* operation
* source
* destination
* start time
* end time
* success/failure/partial status
* error
* number of files
* transferred bytes
* duration

For directory operations, log aggregate statistics.

Do NOT log:

* access keys
* secret keys
* credentials
* authentication tokens
* sensitive authentication data

Use canonical storage paths in logs.

Example:

```text
operation=copy
source=do-spaces-nyc:my-data/documents/
destination=do-spaces-sgp:backup/documents/
status=success
files=128
bytes=84300000000
duration=124.4s
```

---

# 29. Dry Run

Support:

```bash
--dry-run
```

for:

* copy
* move
* rename
* duplicate
* delete
* visibility
* recursive operations

Example:

```text
DRY RUN

COPY

From:
  do-spaces-nyc:my-data/documents/

To:
  do-spaces-sgp:backup/documents/

Objects that would be copied: 128

No changes were made.
```

For directory operations, show the operation scope when available.

Dry-run must never modify storage.

---

# 30. Progress

Large transfers show live progress via `--progress` on transfer commands. The CLI does **not** pass `--progress` to rclone (its ANSI bar needs a TTY and collides with the JSON log). Instead:

1. `--progress` flag sets `TransferOptions::$progress = true`.
2. `HandlesStorageErrors::trackProgress()` registers a handler via `TransferService::reportProgress()` → `RcloneStorageDriver::onProgress()`.
3. The driver feeds parsed `RcloneStats` per stats line.
4. The trait redraws a single `\r` line with bytes/total/percent/transfers/elapsed, cleared by `clearProgress()` before result output.
5. Only rendered when the output is decorated (interactive terminal).

Transfer concurrency and retry are configured via `--transfers` and `--retries`:

```bash
storage copy \
    do-spaces-nyc:my-data/documents/ \
    do-spaces-sgp:backup/documents/ \
    --transfers=6 \
    --retries=5
```

Defaults come from `config('storage.defaults')` (env `STORAGE_DEFAULT_TRANSFERS` / `STORAGE_DEFAULT_RETRIES`).

---

# 31. Configuration

### `config/storage.php`

All values are env-driven (`STORAGE_*`; see `.env.example`).

```php
return [
    'default_driver' => env('STORAGE_DEFAULT_DRIVER', 'rclone'),

    'drivers' => [
        'rclone' => [
            'binary'   => env('STORAGE_RCLONE_BINARY', 'rclone'),
            'timeout'  => (int) env('STORAGE_RCLONE_TIMEOUT', 3600),
        ],
    ],

    'logs' => [
        'path'      => env('STORAGE_LOG_PATH', '~/.config/storage-cli/logs'),
        'max_files' => (int) env('STORAGE_LOG_MAX_FILES', 14),
        'level'     => env('STORAGE_LOG_LEVEL', 'info'),
    ],

    'defaults' => [
        'transfers'  => (int) env('STORAGE_DEFAULT_TRANSFERS', 8),
        'retries'    => (int) env('STORAGE_DEFAULT_RETRIES', 3),
        'overwrite'  => (bool) env('STORAGE_OVERWRITE', false),
        'recursive'  => (bool) env('STORAGE_RECURSIVE', false),
        'acl'        => env('STORAGE_DEFAULT_ACL') ?: null,
    ],

    'cache' => [
        'ttl' => (int) env('STORAGE_CACHE_TTL', 300),
    ],

    'visibility' => [
        'private'              => 'private',
        'public'               => 'public-read',
        'public-read-write'    => 'public-read-write',
        'authenticated-read'   => 'authenticated-read',
    ],
];
```

### `config/commands.php`

```php
return [
    'default' => App\Commands\ListCommand::class,
    'paths'   => [app_path('Commands')],
    'hidden'  => [/* framework commands: DumpCompletion, Help, Schedule*, VendorPublish, StubPublish */],
    'remove'  => [
        Symfony\Component\Console\Command\ListCommand::class,   // app's list owns the name
        NunoMaduro\LaravelConsoleSummary\SummaryCommand::class,
    ],
];
```

Do not store secrets in source code. Do not duplicate credentials already managed by rclone.

---

# 32. Process Execution

`App\Support\RcloneProcess` wraps Symfony Process with array-arg execution:

```php
class RcloneProcess  // intentionally not final — tests extend with FakeRcloneProcess
{
    public function run(array $arguments, ?callable $onOutput = null): ProcessResult;
}
```

* Arguments are always passed as an array — **no** `shell_exec()` or string interpolation.
* `env: getenv()` is passed explicitly (Symfony Process drops `putenv()`-only vars like `RCLONE_CONFIG`).
* Default timeout: 3600s (configurable via `storage.drivers.rclone.timeout`).
* `$onOutput` callback receives each line as it's written — used for live progress stats.

---

# 33. Testing

**Framework:** Pest v4 (PHPUnit-compatible). **One entry:** `./vendor/bin/pest`.

### Test Structure

```text
tests/
├── TestCase.php                          — abstract, extends LaravelZero\Framework\Testing\TestCase
├── Pest.php                              — uses(TestCase)->in('Feature'), helpers
├── Feature/
│   ├── CopyCommandTest.php               — dir copy, file copy, skip/overwrite, dry-run, missing source
│   ├── CopyToCommandTest.php             — alias semantics
│   ├── MoveCommandTest.php               — move + delete source, skip, partial (chmod 555)
│   ├── MoveToCommandTest.php             — alias semantics
│   ├── RenameCommandTest.php             — file rename, prefix rename, skip, dry-run
│   ├── DuplicateCommandTest.php          — file/prefix duplicate, skip, dry-run
│   ├── DeleteCommandTest.php             — force delete, dry-run, declines confirmation, empty dir
│   ├── DownloadCommandTest.php           — dir→local, file→file, dry-run
│   ├── UploadCommandTest.php             — local→local, skip, dry-run
│   ├── VisibilityCommandTest.php         — public/private modes, dry-run, invalid value
│   ├── ListCommandTest.php               — local listing, recursive, --type, missing path
│   ├── WizardCommandTest.php             — list/copy flows, abort, dry-run, delete deferral
│   ├── TransferOptionDefaultsTest.php    --acl/--transfers/--retries defaults from config
│   └── RemotesCommandsTest.php           — add/show/forget with temp RCLONE_CONFIG
├── Unit/
│   ├── StoragePathTest.php               — parsing, normalization, child, asDirectory, errors
│   ├── LocalStorageDriverTest.php        — full driver coverage (list/copy/move/delete/visibility)
│   ├── RcloneStorageDriverTest.php       — FakeRcloneProcess stubs; exists/copy/move/ACL
│   ├── StorageServiceTest.php            — driver dispatch, list, exists, cache TTL
│   ├── RcloneProcessTest.php             — array args, no shell interpolation
│   ├── RcloneStatsTest.php               — JSON parsing, collapse, dedup, error lines
│   ├── SizeFormatterTest.php             — B→P rendering, saturation
│   ├── DurationFormatterTest.php         — ms/s/m/h
│   ├── StorageLoggerTest.php             — writes to configured dir, rotating files
│   └── ProcessTransferJobTest.php        — queued copy runs transfer; failures throw
```

### Key Testing Conventions

* **Pest shares one process across all suites** — top-level helper function names collide across files. Every helper must be unique per file (e.g. `remove_tree_lsd`, `rmtree_ct`, `wizard_fixture`). Never define a bare `remove_tree()`.
* **`expectsOutputToContain()` matches whole formatted lines** — expect per-line, not across multi-line output. Two expectations matching the same line will fail the second.
* **After `Artisan::call()`, capture output once** — `$out = Artisan::output()`. Repeated calls return empty.
* **The `artisan()` PendingCommand emulator** throws `NoMatchingExpectationException` on unregistered prompts. For flows without `expectsQuestion`, use `Artisan::call()` + `Artisan::output()`.
* **Pest ignores PHP `@` suppression** — stray warnings surface. Guard fixture cleanup with `is_dir()` checks.
* **Remote-flow tests** define throwaway `local`-backend remotes via `RCLONE_CONFIG` temp files; require `rclone` on PATH. Never let them touch `~/.config/rclone/rclone.conf`.
* **Real S3/Spaces buckets** enforce bucket-owner ACLs; direct visibility/ACL writes fail. Scope visibility coverage to `LocalStorageDriver`.
* **Manual smoke:** `php storage-manage-cli`; interactive commands need piped stdin (e.g. `script -q /dev/null php storage-manage-cli wizard`).

---

# 34. Command Design

Prefer:

```text
storage list
storage download
storage upload
storage copy
storage move
storage rename
storage duplicate
storage copy-to
storage move-to
storage visibility
storage delete
```

If a different naming scheme is significantly better, explain why before changing it.

Every command should have:

* clear help text
* examples
* argument validation
* useful exit codes
* consistent output
* canonical path syntax

---

# 35. Exit Codes

Defined in `app/Support/ExitCode.php`:

```text
0 = success
1 = general failure (or transfer failure)
2 = invalid arguments / path parse error / path validation error
3 = source not found (object, remote, or bucket)
4 = destination error
5 = visibility / ACL error
6 = partial operation (some objects failed)
```

Commands map exceptions through `HandlesStorageErrors::exitCodeFor()` / `reportException()` rather than hard-coding a code per catch block. `retry` with IDs that match nothing exits 1, not 0.

---

# 36. Security Requirements

The application must:

* never print credentials
* never log credentials
* never use unsafe shell interpolation
* safely handle paths containing spaces
* safely handle special characters
* safely handle filenames beginning with `-`
* validate remote/bucket/path input
* protect destructive operations
* avoid arbitrary command execution
* avoid path traversal where inappropriate
* prevent accidental deletion outside the requested scope

Pay special attention to shell injection because the application executes rclone.

---

# 37. Backward Compatibility

The existing Bash script is the reference behavior.

Before implementation:

1. inspect the legacy script
2. identify every existing operation
3. identify every option
4. identify validation behavior
5. identify configuration behavior
6. identify logging behavior
7. identify safety behavior
8. identify path semantics
9. identify recursive behavior
10. document what will be preserved
11. document what will intentionally change

Do not remove functionality without explaining it.

If legacy syntax conflicts with the new canonical syntax:

```text
<remote>:<bucket>/<path>
```

document the migration clearly.

Do not preserve ambiguous syntax merely for convenience if it makes the new application unsafe or difficult to reason about.

---

# 38. Development Strategy

Do NOT implement the entire application in one giant step.

Implement in phases.

---

## Phase 0 — Legacy Analysis

Before writing production code:

1. Read the attached Bash script completely.
2. Identify every function.
3. Identify every CLI argument.
4. Identify every option.
5. Identify all configuration behavior.
6. Identify all validation behavior.
7. Identify all rclone behavior.
8. Identify AWS CLI/ACL behavior.
9. Identify interactive behavior.
10. Identify logging behavior.
11. Identify retry behavior.
12. Identify transfer behavior.
13. Identify recursive directory behavior.
14. Identify safety behavior.
15. Identify ambiguous behaviors.

Produce:

### A. Feature Inventory

```text
Existing feature
Existing option
Current implementation
New Laravel Zero equivalent
```

### B. Architecture Proposal

```text
Command
 ↓
Application Service
 ↓
StorageDriver Contract
 ↓
Driver
 ↓
rclone / S3 / Local
```

### C. Command Matrix

For every command show:

* arguments
* options
* validation
* confirmation
* dry-run
* recursive behavior
* directory semantics
* underlying operation

### D. Bash → Laravel Zero Mapping

```text
Bash function
        ↓
Application component
        ↓
Service
        ↓
Driver
```

### E. Risk / Ambiguity Report

Identify:

* unsafe shell usage
* credential exposure
* ambiguous path behavior
* directory semantics
* overwrite behavior
* partial operations
* ACL behavior
* cross-storage behavior
* error handling

Then STOP.

Do not write production code yet.

---

## Phase 1 — Foundation

Create:

* Laravel Zero project
* configuration
* command registration
* StorageDriver contract
* StoragePath value object
* StorageLocationType
* RcloneStorageDriver
* process execution abstraction
* basic exceptions
* basic logging

At the end of Phase 1:

```bash
php application list
```

or the configured application command must run successfully.

---

## Phase 2 — Existing Operations

Implement:

```text
list
download
upload
copy
move
visibility
```

These should reproduce the important behavior of the legacy Bash script while following the new architecture and canonical path syntax.

---

## Phase 3 — New File Operations

Implement:

```text
rename
duplicate
copy-to
move-to
delete
```

Include:

* file semantics
* directory semantics
* prefix semantics
* verification
* overwrite policy
* conflict handling
* partial-operation reporting
* safety checks

---

## Phase 4 — Interactive UX

Add:

* interactive storage selection
* bucket selection
* operation selection
* source selection
* destination selection
* confirmation prompts
* progress display
* dry-run presentation
* human-readable output

Interactive and non-interactive flows must ultimately use the same application services and DTOs.

---

## Phase 5 — Testing

Add comprehensive:

* unit tests
* integration tests
* command tests
* directory semantics tests
* failure tests
* partial-operation tests
* path parsing tests

---

## Phase 6 — Polish

Add:

* command documentation
* examples
* consistent errors
* exit codes
* verbose mode
* logging improvements
* configuration improvements
* README
* migration documentation from Bash CLI

---

# 39. Coding Style

Follow modern PHP practices.

Prefer:

* strict types
* typed properties
* constructor dependency injection
* readonly DTOs where appropriate
* enums where appropriate
* interfaces for replaceable infrastructure
* small focused services
* descriptive exceptions
* immutable value objects
* PSR-4 autoloading
* PHPDoc only where it adds useful information

Avoid:

* global state
* giant service classes
* static utility abuse
* duplicated path parsing
* duplicated validation
* direct shell commands in multiple classes
* hidden side effects
* rclone-specific logic in Commands
* business rules inside DTOs
* arbitrary string manipulation of storage paths

---

# 40. Final Architecture

```text
                        ┌───────────────────────────┐
                        │   Laravel Zero 13 CLI     │
                        │   PHP ^8.3 · Pest v4      │
                        │   Entry: storage-manage-cli│
                        └───────────┬───────────────┘
                                    │
                              Commands (20)
                                    │
                    ┌───────────────┼───────────────┐
                    │               │               │
              Transfer cmds    Remote cmds    Config/Wizard cmds
              (copy/move/      (remotes       (configs:save/
               delete/etc.)    add/show/      forget, wizard)
                    │          forget)
                    │               │
            ┌───────┴───────┐       │
            │               │       │
       PresentsOutput  HandlesStorageErrors
            │               │
            ▼               ▼
                    Application Services
                    ┌───────┴────────┐
                    │                │
              TransferService   StorageService
              VisibilityService  RemoteManager
                    │                │
                    ▼                ▼
              StorageDriver Contract    RemoteDiscovery
                    │
        ┌───────────┼───────────┐
        │           │           │
        ▼           ▼           ▼
  RcloneDriver  LocalDriver   (future)
        │           │
        ▼           ▼
    rclone CLI    PHP filesystem
    AWS CLI       (chmod for
    (s3api)        visibility)
        │
  ┌─────┼──────────┐
  ▼     ▼          ▼
 DO    AWS        R2
Spaces S3     (via rclone)
```

### Provider Bindings (`AppServiceProvider`)

```text
singleton: StorageLogger, RcloneProcess, StorageService, TransferService, VisibilityService
bind:      RcloneStorageDriver, LocalStorageDriver  (not singletons — memoised state lasts one command)
```

### Data Flow

```text
Command (parse args, build StoragePath + TransferOptions)
    ↓
Service (orchestrate: assert source exists, choose driver, enforce directory semantics)
    ↓
StorageDriver (translate to backend: rclone flags, chmod, AWS CLI)
    ↓
rclone / PHP filesystem / AWS CLI
    ↓
TransferResult (status, counts, errors) → renderTransfer() → ExitCode
```

### Key Design Decisions

* **Two drivers, one interface.** `RcloneStorageDriver` handles any pair where at least one end is remote; `LocalStorageDriver` handles local↔local. Adding a new backend requires only a new `StorageDriver` implementation.
* **TransferOptions as a DTO, not config reads.** The queued job payload carries concrete option values; jobs never re-read `config()`.
* **Single rclone process per remote transfer.** Per-object loops are only for local↔local moves.
* **Counts from rclone, not predictions.** `RcloneStats::parse()` reads the final `stats` JSON off stderr.
* **ACL resolved before any write.** Destination-remote-only, memoised, throws early on unmappable values.

---

# 41. Mandatory Semantic Rules

The following rules are NON-NEGOTIABLE.

## Storage Path

Public remote path syntax:

```text
<remote>:<bucket>/<path>
```

Example:

```text
do-spaces-nyc:my-data/documents/report.pdf
```

Never use:

```text
do-spaces-nyc/my-data/documents/report.pdf
```

as the public canonical syntax.

---

## Directory Copy

Directory copy means:

```text
SOURCE CONTENTS
       ↓
DESTINATION PREFIX
```

Example:

```text
source:
documents/
├── a.txt
└── nested/b.txt

destination:
backup/
```

result:

```text
backup/
├── a.txt
└── nested/b.txt
```

NOT:

```text
backup/documents/
├── a.txt
└── nested/b.txt
```

unless the destination was explicitly:

```text
backup/documents/
```

---

## Directory Move

```text
COPY
 ↓
VERIFY
 ↓
DELETE SOURCE OBJECTS
```

Failed source objects must remain.

---

## Directory Rename

```text
old-prefix/*
       ↓
new-prefix/*
```

Do not assume native directory rename.

---

## Directory Duplicate

```text
source-prefix/*
       ↓
destination-prefix/*
```

Source remains untouched.

---

## Directory Delete

```text
delete prefix/*
```

Requires confirmation unless explicitly forced.

---

## File-to-Directory

```text
file.pdf
   ↓
destination/
   ↓
destination/file.pdf
```

---

## Cross-Storage

All of these must work through the same service abstraction:

```text
Remote → Remote
Remote → Local
Local → Remote
Local → Local
```

---

## Driver Responsibility

Application semantics belong to:

```text
Services / Domain logic
```

Backend-specific translation belongs to:

```text
Drivers
```

Therefore:

```text
Command
   ↓
Service
   ↓
StoragePath / DTO
   ↓
StorageDriver
   ↓
rclone / S3 / Local FS
```

Never put rclone-specific directory/path rules into Commands.

---

# 42. Acceptance Criteria

The project is acceptable only if:

### Architecture

* Commands contain no storage business logic.
* Services contain application logic.
* Drivers contain infrastructure-specific behavior.
* Storage paths are represented by a dedicated value object.
* Storage credentials are not duplicated.

### Path Syntax

All public examples use:

```text
<remote>:<bucket>/<path>
```

### Directory Semantics

Directory operations consistently preserve relative paths.

### Safety

Destructive operations require confirmation or explicit force.

### Reliability

Move/rename correctly handle:

```text
copy success
verify success
delete failure
```

and report partial completion.

### Testing

Directory/file/cross-storage behavior is covered by automated tests.

### Maintainability

Adding a new backend should not require rewriting Commands.

---

# Your First Task

You are working from the attached legacy Bash script.

DO NOT implement the application yet.

First:

1. Analyze the attached Bash script completely.
2. Produce the Feature Inventory.
3. Produce the Architecture Proposal.
4. Produce the Command Matrix.
5. Produce the Bash → Laravel Zero migration mapping.
6. Analyze the legacy path semantics.
7. Analyze the legacy directory/recursive semantics.
8. Compare them against the mandatory semantics in this prompt.
9. Identify risky or ambiguous behaviors.
10. Identify which existing behaviors should be preserved exactly.
11. Identify which behaviors must intentionally change.
12. Propose the Phase 1 implementation plan.
13. Identify any architectural decisions that require approval.

Then STOP and wait for approval before writing the first production code file.

Do not generate the entire project at once.

Implement one logical phase at a time.

After each phase:

1. Explain what was implemented.
2. Explain what changed.
3. Explain which files were added/modified.
4. Explain how it should be tested.
5. Report any remaining risks or decisions.

Do not silently make major architectural decisions.

When a requirement is ambiguous, explicitly identify the ambiguity and recommend a solution before implementation.
