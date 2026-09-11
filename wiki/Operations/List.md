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
```

## Options

| Option | Description |
| --- | --- |
| `--type=TYPE` | `all` (default), `dirs`, or `files` |
| `-R`, `--recursive` | List recursively |
| `--sort-dir=SORT` | `asc` (default), `desc`, `size`, `size-desc` |
| `--sort-file=SORT` | `asc` (default), `desc`, `size`, `size-desc` |

An unrecognised `--type` or sort value is rejected with [[Exit-Codes|exit code
`2`]] rather than silently returning an empty listing.

## Behavior

- **Missing path** → exit `3` (not found), matching the transfer commands.
- **Empty remote prefix** → exit `3`: on object stores an empty prefix and a
  missing prefix are indistinguishable, so both report the same way.
- **Empty local directory** → lists `(empty)` and exits `0`.
- The listing ends with a `SUMMARY` line showing file/dir counts and total size.

## Related

- [[Path-Syntax|Path Syntax]]
- [[Upload]] / [[Download]] — moving data in and out before you list it