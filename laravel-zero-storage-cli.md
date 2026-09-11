# Laravel Zero Storage CLI — Development Task

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

# 4. Suggested Project Structure

Use a structure similar to:

```text
app/
├── Commands/
│   ├── ListCommand.php
│   ├── DownloadCommand.php
│   ├── UploadCommand.php
│   ├── CopyCommand.php
│   ├── MoveCommand.php
│   ├── RenameCommand.php
│   ├── DuplicateCommand.php
│   ├── CopyToCommand.php
│   ├── MoveToCommand.php
│   ├── DeleteCommand.php
│   └── VisibilityCommand.php
│
├── Contracts/
│   └── StorageDriver.php
│
├── Drivers/
│   ├── RcloneStorageDriver.php
│   └── LocalStorageDriver.php
│
├── Services/
│   ├── StorageService.php
│   ├── TransferService.php
│   └── FileOperationService.php
│
├── DTOs/
│   ├── StoragePath.php
│   ├── TransferRequest.php
│   ├── CopyRequest.php
│   ├── MoveRequest.php
│   ├── RenameRequest.php
│   └── VisibilityRequest.php
│
└── Support/
    ├── Rclone.php
    ├── Console.php
    └── Result.php
```

You may adjust this structure if there is a better Laravel Zero architecture, but keep the same separation of concerns.

---

# 5. StorageDriver Contract

Create a storage abstraction.

Example:

```php
interface StorageDriver
{
    public function list(string $path, bool $recursive = false): array;

    public function exists(string $path): bool;

    public function download(
        string $source,
        string $destination
    ): void;

    public function upload(
        string $source,
        string $destination
    ): void;

    public function copy(
        string $source,
        string $destination
    ): void;

    public function move(
        string $source,
        string $destination
    ): void;

    public function rename(
        string $source,
        string $destination
    ): void;

    public function delete(string $path): void;

    public function visibility(
        string $path,
        string $visibility
    ): void;
}
```

Do not blindly copy this interface if a better abstraction is required.

Think about:

* files vs directories
* remote vs local paths
* metadata
* recursive operations
* streaming
* errors
* progress
* visibility
* atomicity
* partial operations

The contract should represent **application-level storage semantics**, not rclone-specific semantics.

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

Large transfers should show useful progress.

Preserve legacy configuration concepts:

```text
--transfers
--retries
```

Example:

```bash
storage copy \
    do-spaces-nyc:my-data/documents/ \
    do-spaces-sgp:backup/documents/ \
    --transfers=6 \
    --retries=5
```

Use rclone's progress capabilities where appropriate.

Do not recreate a transfer engine inside PHP if rclone already handles it reliably.

Laravel Zero is the orchestration/UI layer.

Rclone remains the initial transfer engine.

---

# 31. Configuration

Create Laravel-style configuration.

For example:

```text
config/storage.php
```

Example:

```php
return [
    'default_driver' => 'rclone',

    'drivers' => [
        'rclone' => [
            'binary' => 'rclone',
        ],
    ],

    'logs' => [
        'path' => '~/.config/storage-cli/logs',
    ],
];
```

Do not store secrets in source code.

Do not duplicate credentials already managed by rclone.

---

# 32. Process Execution

Create a dedicated process execution abstraction where appropriate.

Use Symfony Process or an equivalent safe process abstraction.

Prefer:

```php
Process::run([
    'rclone',
    'copy',
    $source,
    $destination,
]);
```

Do NOT use unsafe shell interpolation:

```php
shell_exec("rclone copy {$source} {$destination}");
```

Arguments must be passed separately.

The process abstraction should support:

* exit code
* stdout
* stderr
* timeout
* retries where appropriate
* dry-run
* verbose diagnostics

---

# 33. Testing

Use PHPUnit or Pest if compatible with the Laravel Zero version.

## Unit Tests

Test:

* StoragePath parsing
* StoragePath normalization
* local path detection
* remote path detection
* bucket root detection
* prefix/directory detection
* filename extraction
* command argument validation
* visibility mapping
* DTOs
* service behavior
* error handling
* directory relative-path calculation
* conflict handling
* partial-operation behavior

## Integration Tests

Mock/fake the storage driver where possible.

Test:

```text
copy
move
rename
duplicate
copy-to
move-to
delete
visibility
download
upload
list
```

## Directory Acceptance Tests

At minimum test:

```text
source:
remote:bucket/src/

files:
src/a.txt
src/b.txt
src/nested/c.txt

destination:
remote2:bucket/dst/
```

Expected:

```text
remote2:bucket/dst/a.txt
remote2:bucket/dst/b.txt
remote2:bucket/dst/nested/c.txt
```

NOT:

```text
remote2:bucket/dst/src/a.txt
```

unless explicitly requested.

Also test:

```text
file → file
file → directory
directory → directory
directory → new directory
directory → existing directory
empty directory
remote → remote
remote → local
local → remote
local → local
```

Do NOT make real destructive operations against production storage during automated tests.

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

Use meaningful exit codes.

At minimum:

```text
0 = success
1 = general failure
2 = invalid arguments
3 = source not found
4 = destination error
5 = permission/visibility error
6 = partial operation
```

Do not force these exact values if Symfony Console/Laravel Zero has a better established convention.

The important requirement is predictable behavior.

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

The final application should feel like a professional CLI storage manager, not a PHP version of a Bash script.

Target architecture:

```text
                    ┌──────────────────────┐
                    │    Laravel Zero CLI  │
                    └──────────┬───────────┘
                               │
                         Commands
                               │
                               ▼
                    Application Services
                               │
                               ▼
                    StorageDriver Contract
                               │
             ┌─────────────────┼─────────────────┐
             │                 │                 │
             ▼                 ▼                 ▼
       Rclone Driver      S3 Driver        Local Driver
             │                 │                 │
             ▼                 ▼                 ▼
          rclone          S3 API          Local FS
             │
       ┌─────┼──────────────┐
       ▼     ▼              ▼
      DO    AWS             R2
    Spaces
```

The CLI should provide:

```text
List
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

while keeping the underlying storage implementation replaceable.

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
