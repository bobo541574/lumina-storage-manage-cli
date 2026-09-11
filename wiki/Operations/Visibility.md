# Visibility

Set or preview the visibility (ACL) of an object, or recursively of everything
under a prefix. Only `private` and `public` are exposed to users; the mapping to
backend ACL names happens inside the driver.

## Usage

```text
storage visibility <path> <visibility> [options]
```

`visibility` is one of `private` or `public` (raw backend ACL values such as
`public-read` are accepted for advanced use).

## Examples

```bash
# Make a file public
storage visibility do-spaces-nyc:my-data/photo.jpg public

# Make a file private
storage visibility do-spaces-nyc:my-data/photo.jpg private

# Apply recursively to a prefix
storage visibility do-spaces-nyc:my-data/public/ public --recursive

# Preview which objects would change
storage visibility do-spaces-nyc:my-data/public/ public --recursive --dry-run
```

## Options

| Option | Description |
| --- | --- |
| `--recursive` | Apply to every object under the prefix |
| `--dry-run` | Preview without changing storage |
| `-v`, `--verbose` | Extra diagnostics on failure |

Visibility is **not** recursive by default — use `--recursive` explicitly. The
CLI never silently assumes a recursive ACL change.

## How it works

| Backend | Mechanism |
| --- | --- |
| Remote (rclone/S3) | Enumerate keys, then `aws s3api put-object-acl` per key using credentials from the rclone remote config |
| Local | `chmod` — public → `755` dirs / `644` files, private → `700` dirs / `600` files |

## Errors

- **Invalid visibility value** → [exit `2`](Exit-Codes).
- **Missing path** → exit `3`.
- **ACL / permission failure** → exit `5`.

Real S3/Spaces buckets often enforce bucket-owner ACLs and reject direct ACL
writes; visibility behavior is fully covered by tests against the local driver.

## Related

- [ACL Handling](Acl-Handling) — how remote ACLs are resolved before writes
- [Copy](Copy) / [Upload](Upload) — setting ACLs on freshly written objects via `--acl`