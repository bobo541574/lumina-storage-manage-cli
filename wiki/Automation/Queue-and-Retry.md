# Queue & Retry

Run transfers as background jobs with `--queue`, inspect and re-dispatch failed
jobs with `retry`.

## When to use

- Large or long-running transfers that don't need to block a terminal.
- Operations that should survive the shell session closing.
- Automating transfers from cron or scripts.

## Enabling the database queue

`--queue` on its own can't persist jobs unless the queue lives in the database:

```bash
export QUEUE_CONNECTION=database
```

(Under the default `sync` connection, `--queue` still works but runs the job
in-process — it prints "Queued" then executes immediately.)

## Queueing a transfer

```bash
storage copy src:bucket/data/ dst:bucket/backup/ --queue

# Process queued jobs
storage queue:work database --stop-when-empty
```

The job payload carries **concrete option values** (transfers, retries, dry-run,
overwrite, recursive, progress, ACL) so the worker never needs to re-read config.

## Retrying failures

Failed jobs land in the `failed_jobs` table (each job tries 3 times first).

```bash
# List failed jobs
storage retry

# Retry one or more specific jobs
storage retry 5
storage retry 5 8 12

# Retry everything
storage retry all
```

### Retry exit behavior

| Situation | Exit code |
| --- | --- |
| All matched IDs re-dispatched | `0` |
| Some matched, some didn't | `6` (partial) |
| Nothing matched | `1` (re-dispatching nothing is a failure) |

## Full smoke flow

```bash
QUEUE_CONNECTION=database storage copy src:bucket/data/ dst:bucket/backup/ --queue
QUEUE_CONNECTION=database storage queue:work database --stop-when-empty --tries=1
storage retry <id>
QUEUE_CONNECTION=database storage queue:work database --stop-when-empty
```

## Related

- [[Exit-Codes|Exit Codes]]
- [[Error-Handling|Error Handling]] — why a job may fail
- [[Configuration]] — queue, database, and failed-job variables