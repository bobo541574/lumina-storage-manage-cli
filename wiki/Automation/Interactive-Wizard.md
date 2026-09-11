# Interactive Wizard

A guided, prompt-driven operation builder. `wizard` (alias `interactive`) walks
you through selecting storage, a bucket or directory, an operation, and its
paths — then runs the same commands and services the non-interactive CLI uses.

## Usage

```text
storage wizard [options]
```

## Examples

```bash
# Launch the wizard
storage wizard

# Preload an operation from a saved profile
storage wizard --config my-backup

# Save the completed operation as a reusable profile
storage wizard --save-as daily-backup
```

## Flow

1. **Select storage** — one of your configured rclone remotes, or `local`.
2. **Root selection**
   - Local → a working directory (defaults to your current or home directory).
   - Remote → pick a **bucket** (or enter one manually).
3. **Select operation** — List, Download, Upload, Copy, Move, Rename, Duplicate,
   Copy To, Move To, Visibility, Delete.
4. **Operation-specific prompts**
   - Transfers: source path → destination path → overwrite → dry run → live
     progress → confirm.
   - Visibility: object path → `private` / `public`.
   - Delete: path to delete → confirmation with the deletion scope.
5. **Run** — the wizard `call`s the matching command in-process, so validation,
   output, and exit codes are identical to typing the command yourself.

Paths you type are resolved against the selected root: absolute paths and
`remote:bucket/...` values pass through; bare relative paths are prefixed with
the root.

## Saved profiles

Run `wizard --save-as <name>` to persist the operation, or use `--config <name>`
to reload one. Profiles are managed from the [[Saved-Configs]] page.

Example screen:

```text
? Select storage:               ❯ do-spaces-nyc
? Select bucket:                ❯ my-data
? Select operation:             ❯ Copy
? Source path:                  videos/
? Destination path:             backup/videos/
? Overwrite existing objects?   No
? Dry run (no changes)?         Yes
  Plan: copy do-spaces-nyc:my-data/videos/ -> do-spaces-sgp:backup/movies/
? Run operation?                Yes
```

## Notes

- The interactive flow and the non-interactive commands share the same
  `StoragePath` values, services, and DTOs.
- Interactive smoke-testing needs piped stdin:

  ```bash
  script -q /dev/null php storage-manage-cli wizard <<< $'...'
  ```

## Related

- [[Saved-Configs|Saved Configs]]
- [[Remotes]] — where the remote options come from