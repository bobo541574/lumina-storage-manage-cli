# Storage Manager CLI

> Production-grade **Laravel Zero** CLI for managing files and objects across
> rclone remotes, S3-compatible storage (DigitalOcean Spaces, AWS S3, Cloudflare
> R2), and local filesystems.

The CLI wraps `rclone` for transfers and the AWS CLI for remote ACL changes,
serializing every operation through a small, layered PHP architecture:
**Commands** parse input and render output, **Services** hold transfer and
visibility logic, and **Drivers** talk to the backends.

## Quick Start

```bash
# Development entry
php storage-manage-cli <command>

# Build a standalone phar
php storage-manage-cli app:build storage
./builds/storage <command>
```

Requirements: PHP ^8.3, [rclone](https://rclone.org) on `PATH`, and the AWS CLI
(`s3api`) for remote visibility changes.

## Feature Pages

### Operations

- [[List]] — browse objects and directories
- [[Copy]] — copy objects / prefixes between locations (`copy`, `copy-to`)
- [[Move]] — copy → verify → delete source (`move`, `move-to`)
- [[Rename]] — rename / relocate objects and prefixes
- [[Duplicate]] — non-destructive copy
- [[Delete]] — delete objects and prefixes
- [[Download]] — remote → local
- [[Upload]] — local → remote
- [[Visibility]] — set object/prefix visibility (private / public)

### Automation

- [[Interactive-Wizard|Interactive Wizard]] — guided operation builder
- [[Remotes]] — manage rclone remotes
- [[Saved-Configs|Saved Configs]] — reusable transfer profiles
- [[Queue-and-Retry|Queue & Retry]] — background transfers and failed-job retry

### Under the Hood

- [[Path-Syntax|Path Syntax]] — canonical `<remote>:<bucket>/<path>` syntax
- [[Acl-Handling|ACL Handling]] — how ACLs are resolved before every write
- [[Progress-and-Reporting|Progress & Reporting]] — badges, live progress, counts
- [[Error-Handling|Error Handling]] — exceptions, grouped errors, log
- [[Exit-Codes|Exit Codes]] — predictable status codes
- [[Configuration]] — environment variables
- [[Architecture]] — layered design and service wiring

### Foundation

- [[Quick-Start|Quick Start]] — install, build, first commands
- [[Path-Syntax|Path Syntax]]
- [[Architecture]]

## Path Syntax in One Line

```text
<remote>:<bucket>/<path>      # rclone remote
/path/to/local/dir/           # local filesystem
local:/path/to/local/dir      # explicit local
```

A **trailing slash** marks a directory/prefix. Copying a directory copies its
**contents** into the destination — the source name is never nested.

## Shared Options

Most operations share a common option surface:

| Option | Description |
| --- | --- |
| `--overwrite` | Replace existing destination objects |
| `--dry-run` | Preview without modifying storage |
| `--transfers=N` | Parallel transfers (default: 8) |
| `--retries=N` | Retries on failure (default: 3) |
| `--progress` | Show live transfer progress |
| `--acl=...` | Visibility/ACL for written objects |
| `--queue` | Dispatch as a background job |
| `-v`, `--verbose` | Verbose rclone output + stack trace on failure |