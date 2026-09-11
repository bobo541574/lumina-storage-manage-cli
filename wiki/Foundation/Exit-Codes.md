# Exit Codes

Every command returns a predictable exit code. Commands map exceptions through a
shared handler rather than hard-coding a code in each catch block.

| Code | Meaning |
| --- | --- |
| `0` | Success |
| `1` | General failure / operation error |
| `2` | Invalid arguments, path parse error, invalid option value |
| `3` | Source / remote / bucket not found |
| `4` | Destination error |
| `5` | Visibility / ACL error |
| `6` | Partial operation (some objects failed) |

## What maps where

| Condition | Code |
| --- | --- |
| Success, nothing to do, dry run | `0` |
| Unparsable path, bad `--type`, invalid visibility value | `2` |
| Missing source object / prefix, unknown remote, unknown bucket | `3` |
| Failed transfer, unexpected error | `1` |
| Visibility/ACL failure | `5` |
| Some objects failed, some succeeded | `6` |

## Notable behavior

- A **missing source** exits `3` — a typo can never look like a completed
  operation.
- **`retry` with IDs that match nothing** exits `1` (re-dispatching nothing is a
  failure). When some IDs match and others don't, it exits `6` (partial).
- An **empty local directory** lists `(empty)` and exits `0`; an empty *remote*
  prefix is indistinguishable from a missing one and reports as not found.
- **Partial operations** (`6`) are never dressed up as success.

## Related

- [Error Handling](Error-Handling) — how errors are surfaced and grouped
- [Progress & Reporting](Progress-and-Reporting) — result badges