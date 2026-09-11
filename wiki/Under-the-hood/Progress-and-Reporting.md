# Progress & Reporting

Every command prints a consistent visual style: an operation header, aligned
detail rows, and a result badge with elapsed time.

## Header & details

```text
  RENAME    DRY RUN

  Source       do-spaces-nyc:my-data/tickets/
  Destination  do-spaces-nyc:my-data/_trash-tickets/
```

## Result badges

| Badge | Meaning | Exit code |
| --- | --- | --- |
| `SUCCESS` | Every object was handled as requested | `0` |
| `PARTIAL` | Some handled, some failed | `6` |
| `FAILED` | The operation failed | `1` |
| `DRY RUN` | Nothing was written; numbers are a prediction | `0` |
| `INFO` | Nothing needed doing — never shown for a simulation | `0` |

```text
  SUCCESS   Copied 98 objects, skipped 4.  (12.4s)
```

"Nothing to do" is an INFO outcome, never a DRY RUN — a real delete that removed
nothing must not look like a simulation.

## Counts are measured, not predicted

Every rclone invocation runs with `--use-json-log --stats=1s --stats-one-line
--stats-log-level NOTICE`. The reported `copied` / `skipped` / `failed` numbers
come from rclone's **own final counters** — exit codes alone can't distinguish
"copied 4370" from "copied nothing because everything already existed". Only
`--dry-run` estimates counts from a listing.

## Live progress

`--progress` renders a single live line, erased when the operation finishes:

```text
  ▸  1.4G / 2.6G  ·  53%  ·  2318 transferred  ·  1m 12s
```

It is **only** drawn on an interactive terminal, so piping or redirecting output
leaves the log clean. The CLI does not forward `--progress` to rclone (its ANSI
bar needs a TTY and would collide with the JSON log); the line is fed from the
parsed stats stream instead.

## Errors are grouped, not repeated

rclone logs each failure once per retry with a unique request ID, so one
failing prefix could produce tens of thousands of near-identical lines.
Identical failures collapse into one entry with a count and an example object:

```text
  ✖  Failed to copy: … api error InvalidArgument: UnknownError  (4370 objects, e.g. attachments/01KKK….png)
```

At most five distinct failures are shown inline; the full detail goes to the
log directory (`STORAGE_LOG_PATH`).

## Related

- [Error Handling](Error-Handling)
- [Exit Codes](Exit-Codes)
- [Configuration](Configuration) — logging variables