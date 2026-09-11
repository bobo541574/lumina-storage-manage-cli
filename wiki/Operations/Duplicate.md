# Duplicate

Copy an object or prefix under a new name while keeping the source untouched.
This is [[Copy]] in non-destructive form.

## Usage

```text
storage duplicate <source> <destination> [options]
```

## Examples

```bash
# Duplicate a single file
storage duplicate do-spaces-nyc:my-data/report.pdf do-spaces-nyc:my-data/report-backup.pdf

# Duplicate a whole prefix
storage duplicate do-spaces-nyc:my-data/configs/ do-spaces-nyc:my-data/configs-backup/

# Dry run
storage duplicate src:bucket/configs/ dst:bucket/configs-backup/ --dry-run
```

## Semantics

| Operation | Source kept? | Engine |
| --- | --- | --- |
| [[Duplicate]] | ✅ Always | copy |
| [[Copy]] | ✅ Always | copy |
| [[Move]] | ❌ After verification | copy → verify → delete |

Duplicate never removes the source. Existing destination objects are kept by
default; add `--overwrite` to replace them.

## Options

Identical to [[Copy#options]]: `--overwrite`, `--dry-run`, `--transfers`,
`--retries`, `--progress`, `--acl`, `--queue`, `-v/--verbose`.

## Directory semantics

Prefix duplication follows the same contents-into-destination rules as
[[Copy#directory-semantics|Copy]]:

```text
storage duplicate src:bucket/documents/ dst:bucket/documents-copy/
→  documents/*  AND  documents-copy/*
```

Both prefixes remain afterward.

## Related

- [[Copy]]
- [[Rename]] — copy then delete the source