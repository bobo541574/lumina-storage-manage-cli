# List

Browse objects and directories under a local path or remote prefix.

## Usage

```text
storage list [<path>] [options]
```

Running without a path prints the command index.

## Examples

```bash
# List a remote bucket prefix
storage list do-spaces-nyc:my-data/videos/

# List a local directory
storage list /home/user/downloads/

# List only directories
storage list do-spaces-nyc:my-data/ --type dirs

# List only files, recursively (-R is short for --recursive)
storage list do-spaces-nyc:my-data/ --type files -R

# Sort directories by size descending, files by name ascending
storage list remote:bucket/dir/ --sort-dir size-desc --sort-file asc

# Sort files smallest → largest
storage list remote:bucket/dir/ --sort-file size

# Show each directory's recursive total size
storage list remote:bucket/dir/ --size
```

## Options

| Option | Description |
| --- | --- |
| `--type=TYPE` | `all` (default), `dirs`, or `files` |
| `-R`, `--recursive` | List recursively |
| `--size` | Show the total size of each directory |
| `--sort-dir=SORT` | `asc` (default), `desc`, `size`, `size-desc` |
| `--sort-file=SORT` | `asc` (default), `desc`, `size`, `size-desc` |

An unrecognised `--type` or sort value is rejected with [exit code `2`](Exit-Codes)
rather than silently returning an empty listing.

## Behavior

- **Missing path** → exit `3` (not found), matching the transfer commands.
- **Empty remote prefix** → exit `3`: on object stores an empty prefix and a
  missing prefix are indistinguishable, so both report the same way.
- **Empty local directory** → lists `(empty)` and exits `0`.
- The listing ends with a `SUMMARY` line showing file/dir counts and total size.
- `--size` prints each directory's **recursive** total (every object under it),
  which is why `--sort-dir size` becomes useful with it. rclone reports no
  directory size directly, so this costs one full walk of the tree — one extra
  `rclone lsf -R` pass, or none at all when the listing is already recursive.
  Leave it off for directory listings that only need to be fast.

## Related

- [Path Syntax](Path-Syntax)
- [Upload](Upload) / [Download](Download) — moving data in and out before you list it