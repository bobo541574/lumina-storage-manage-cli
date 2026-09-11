# Saved Configs

Persist and reuse transfer profiles (the same profiles the
[[Interactive-Wizard|wizard]] loads with `--config` and stores with `--save-as`).

## Usage

```text
storage configs                # list saved profiles (alias: list-configs)
storage configs:save <name> <source> <destination> [options]
storage configs:forget <name>  # delete a profile
```

## Examples

```bash
# List saved profiles
storage configs
storage list-configs

# Save a transfer as a profile
storage configs:save daily-backup src:bucket/data/ dst:bucket/backup/ \
  --operation=copy --overwrite --recursive

# Load it back into an interactive session
storage wizard --config daily-backup

# Delete a profile
storage configs:forget daily-backup
```

## What is stored

Each profile records:

- **name**
- **storage** (remote name or `local`) and **bucket**
- **operation**
- **source** and **destination** paths
- **options** (overwrite, recursive, …)

Only paths and option flags are stored — never credentials. Profile data lives
in the `saved_configs` table of the app database.

## `configs:list` output

| Column | Contents |
| --- | --- |
| Name | Profile name |
| Operation | copy / move / … |
| Storage | remote or bucket |
| Source | source path |
| Destination | destination path |

## Related

- [[Interactive-Wizard|Interactive Wizard]] — `--config` / `--save-as`
- [[Configuration]] — database variables that back the store