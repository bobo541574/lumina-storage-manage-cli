# Delete

Delete a single object or everything under a prefix.

## Usage

```text
storage delete <path> [options]
```

## Examples

```bash
# Delete an object (confirmation prompt)
storage delete do-spaces-nyc:my-data/report.pdf

# Delete everything under a prefix
storage delete do-spaces-nyc:my-data/tmp/

# Skip confirmation
storage delete do-spaces-nyc:my-data/tmp/ --force

# Preview what would be deleted
storage delete do-spaces-nyc:my-data/tmp/ --dry-run
```

## Safety

- **Interactive confirmation is required** unless `--force`.
- Before doing anything, the command checks the path and shows the **scope**:

  ```text
  Delete prefix:

  do-spaces-nyc:my-data/documents/

  Objects: 1,284
  Total size: 84.3 GB

  Continue?
  ```

- **Missing path** → exit `3` with `Nothing found at: …` — a typo can never
  look like a completed deletion.
- **Existing but empty path** → `Nothing to delete.` and exit `0`.
- An **unparseable path** → exit `2`.
- `--dry-run` prints the scope and **removes nothing**. "Nothing to do" is
  rendered as an INFO badge — a real delete that removed nothing is never
  dressed up as a simulation.

## Options

| Option | Description |
| --- | --- |
| `--force` | Delete without confirmation (required to skip the prompt) |
| `--dry-run` | Show the scope and what would be deleted; make no changes |
| `-v`, `--verbose` | Extra diagnostics on failure |

## Related

- [Exit Codes](Exit-Codes)
- [Progress & Reporting](Progress-and-Reporting)
- [Interactive Wizard](Interactive-Wizard) (deletion flow)