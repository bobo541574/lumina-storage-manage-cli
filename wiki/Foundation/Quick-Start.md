# Quick Start

## Requirements

- PHP ^8.3
- [rclone](https://rclone.org) on `PATH` — required for any remote operation
- AWS CLI (`aws`, `s3api`) — required only for remote visibility changes

No Laravel framework installation is needed to run the CLI.

## Install / Run

```bash
# Run directly in development
php storage-manage-cli <command>

# Or build a standalone binary and run it anywhere
php storage-manage-cli app:build storage
./builds/storage <command>
```

Running with no command prints the full command index.

## First Commands

```bash
# List configured rclone remotes
storage remotes

# List a remote bucket prefix
storage list do-spaces-nyc:my-data/videos/

# Copy a directory/prefix to another remote (contents, structure preserved)
storage copy do-spaces-nyc:my-data/videos/ do-spaces-sgp:backup/movies/

# Preview a move without changing anything
storage move src:bucket/data/ dst:bucket/data/ --dry-run
```

## Configure

Copy `.env.example` to `.env` and set the values you need. Most operations work
with no configuration at all — remotes are read straight from your rclone
config. See [[Configuration]] for the full variable reference.

```bash
STORAGE_DEFAULT_TRANSFERS=8
STORAGE_DEFAULT_RETRIES=3
STORAGE_LOG_PATH=~/.config/storage-cli/logs
QUEUE_CONNECTION=sync      # or "database" to enable --queue / retry
```

## Verify

```bash
./vendor/bin/pest    # full test suite
```

See [[Architecture]] for how the pieces fit together, or jump straight to a
feature page: [[List]], [[Copy]], [[Move]], [[Delete]], [[Visibility]].