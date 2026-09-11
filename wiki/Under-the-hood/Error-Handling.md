# Error Handling

Errors are surfaced as readable application messages, grouped instead of
repeated, and mapped to predictable [[Exit-Codes|exit codes]].

## Exception hierarchy

All failures derive from `StorageException`:

| Exception | Raised when |
| --- | --- |
| `PathParseException` | A path can't be parsed safely (→ exit `2`) |
| `PathValidationException` | An invalid value (bad visibility, bad option) (→ exit `2`) |
| `ObjectNotFoundException` | Source object/prefix missing (→ exit `3`) |
| `RemoteNotFoundException` | Remote not configured (→ exit `3`) |
| `BucketNotFoundException` | Bucket not found (→ exit `3`) |
| `TransferException` | Transfer failure, incl. unmappable ACLs (→ exit `1`) |
| `DeleteException` | Delete operation failed |
| `RenameException` | Rename failed / partially failed |
| `VisibilityException` | Visibility/ACL failure (→ exit `5`) |

## Presentation

Primary user-facing errors use a single red-marker style:

```text
  ✖  Source object not found:

     do-spaces-nyc:my-data/report.pdf
```

Raw rclone/AWS shell output is never the primary experience. With `-v` /
`--verbose`, the exception class and a file:line stack trace are printed too.

## Grouped error lines

As described on [[Progress-and-Reporting]], identical failures collapse to one
line with an object count and an example key; `RequestID`/`HostID` fragments are
stripped, and rclone's `Attempt N/M failed` retry summaries are dropped so one
failure isn't listed once per retry. At most five distinct errors are shown
inline; the full list is in the log.

## Partial operations

A move/rename/delete that handled some objects and failed others reports
PARTIAL — never SUCCESS — and exits `6`. Failed source objects remain intact and
can be retried.

## Logging

Operation metadata is logged to `STORAGE_LOG_PATH` (rotated daily):

- operation, source, destination
- status, counts, bytes, duration

**Credentials are never logged.** Both drivers log metadata only; secrets stay
in the rclone config.

## Related

- [[Exit-Codes|Exit Codes]]
- [[Progress-and-Reporting|Progress & Reporting]]
- [[Configuration]] — `STORAGE_LOG_*` variables