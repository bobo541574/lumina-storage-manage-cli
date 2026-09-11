# Configuration

All settings are environment-driven. Copy `.env.example` to `.env`. Most
operations need no configuration — remotes are read from your existing rclone
config.

## Variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `STORAGE_DEFAULT_DRIVER` | `rclone` | `rclone` or `local` |
| `STORAGE_RCLONE_BINARY` | `rclone` | Path to the rclone binary |
| `STORAGE_RCLONE_TIMEOUT` | `3600` | Subprocess timeout (seconds) |
| `STORAGE_LOG_PATH` | `~/.config/storage-cli/logs` | Log directory |
| `STORAGE_LOG_MAX_FILES` | `14` | Log retention (days) |
| `STORAGE_LOG_LEVEL` | `info` | Minimum level written to the log |
| `STORAGE_DEFAULT_TRANSFERS` | `8` | Default parallel transfers |
| `STORAGE_DEFAULT_RETRIES` | `3` | Default retries on failure |
| `STORAGE_OVERWRITE` | `false` | Default overwrite behavior |
| `STORAGE_DEFAULT_ACL` | *(empty)* | ACL for written objects when `--acl` is omitted; empty inherits the destination remote's own `acl` |
| `STORAGE_RECURSIVE` | `false` | Default `--recursive` for commands that accept it |
| `STORAGE_CACHE_TTL` | `300` | Remote/bucket discovery cache TTL (`0` disables) |
| `CACHE_STORE` | `file` | Cache store: `file` persists across runs, `array` does not |
| `QUEUE_CONNECTION` | `sync` | `sync` runs in-process; `database` enables `--queue` / `retry` |
| `DB_CONNECTION` | `sqlite` | Database driver |
| `QUEUE_FAILED_DRIVER` | `database-uuids` | Where failed queue jobs are recorded |

## CLI option precedence

Command-line options override config values; config is only a default:

```text
--acl > STORAGE_DEFAULT_ACL > destination remote's own acl > rclone default
```

## Logging

Logs live in `STORAGE_LOG_PATH` and are rotated daily. Only

- operation
- source / destination
- status
- counts and bytes
- duration

are logged. **Credentials are never logged** — both drivers log metadata only,
and remote secrets stay in the rclone config.

## Secrets

This CLI adds a `--queue`/`retry` system and the saved-configs store on top of
rclone. Remote credentials (access keys, tokens, endpoints) are **never** stored
in the app — they live only in `~/.config/rclone/rclone.conf`. See
[[Remotes]] to manage them safely from the CLI.

## Related

- [[Quick-Start|Quick Start]]
- [[Acl-Handling|ACL Handling]]
- [[Queue-and-Retry|Queue & Retry]]