# ACL Handling

S3 accepts only its **canned** ACL names (`private`, `public-read`,
`public-read-write`, `authenticated-read`, …). An rclone remote configured with
anything else — `acl = public` is a common one — makes rclone send that value on
every write, and the endpoint rejects each server-side copy with an opaque
`400 InvalidArgument`.

This CLI resolves the destination remote's configured ACL **before transferring**
so such remotes keep working, and reports unmappable values as a configuration
error before a single object is touched.

## Precedence

| # | Source | Notes |
| --- | --- | --- |
| 1 | `--acl` on the command | Highest; mapped through `config/storage.php` |
| 2 | `STORAGE_DEFAULT_ACL` (`storage.defaults.acl`) | Pins one ACL for every transfer |
| 3 | The destination remote's own `acl` | Mapped the same way; default when 1–2 are unset |
| 4 | rclone's own default | When no ACL is configured anywhere |

Only the **destination** side is consulted — a local destination performs no S3
write, so no ACL resolution is done for it.

## What the driver does

1. Take the ACL from `--acl` or `STORAGE_DEFAULT_ACL` (arrives as
   `TransferOptions::$acl`).
2. If unset, read the destination remote's `acl` key (memoised via
   `rclone config show`).
3. If the value is a **canned** S3 ACL → pass it through as `--acl=<value>`.
4. Otherwise map it through `config('storage.visibility')` into an explicit
   `--s3-acl=<value>` (e.g. `public` → `public-read`).
5. A value that maps to nothing valid → **throw before any transfer**:

   ```text
   ✖  acl = totally-made-up (in the rclone config for remote "myremote") is not a
      valid S3 ACL, and no mapping for it exists in config/storage.php.
   ```

## Using it

```bash
# Every written object private unless --acl says otherwise
STORAGE_DEFAULT_ACL=private storage copy src:bucket/a/ dst:bucket/b/

# One-off override on the command line
storage copy src:bucket/a/ dst:bucket/b/ --acl public
```

Leave `STORAGE_DEFAULT_ACL` empty (default) to keep each remote's intended
visibility.

## Fixing an invalid remote config

```bash
rclone config update myremote acl public-read
```

## Related

- [[Visibility]] — set ACLs on existing objects
- [[Configuration]] — the `visibility` mapping in `config/storage.php`
- [[Upload]] / [[Copy]] — where `--acl` is applied on writes