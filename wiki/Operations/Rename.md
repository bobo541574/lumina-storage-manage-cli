# Rename

Rename or relocate an object or a whole prefix. `rename` shares [Move](Move)'s
engine and semantics (copy → verify → delete source).

## Usage

```text
storage rename <source> <new-location> [options]
```

## Examples

```bash
# Rename a single file
storage rename do-spaces-nyc:my-data/old-name.pdf do-spaces-nyc:my-data/new-name.pdf

# Move to a different prefix
storage rename do-spaces-nyc:my-data/report.pdf do-spaces-nyc:archive/report.pdf

# Relocate a whole prefix (e.g. into a trash prefix)
storage rename do-spaces-nyc:my-data/tickets/ do-spaces-nyc:my-data/_trash-tickets/

# Dry run
storage rename do-spaces-nyc:my-data/tickets/ do-spaces-nyc:my-data/_trash-tickets/ --dry-run
```

## Prefix rename

Renaming a prefix means:

```text
my-data/old-name/*
        ↓
my-data/new-name/*
```

followed by deletion of the successfully copied source objects. Object storage
generally has no native directory rename, so the driver performs the safe
copy → verify → delete without assuming one.

## Failures

A rename is reported **partially** if copies fail or a source object cannot be
deleted — never as a silent success:

```text
Copy succeeds
Verify succeeds
Delete fails
→ PARTIAL — "Source could not be deleted: …"
```

A source that does not exist exits `3` instead of reporting a successful no-op.

## Related

- [Move](Move) — the underlying engine
- [Duplicate](Duplicate) — copy without deleting the source
- [Exit Codes](Exit-Codes)