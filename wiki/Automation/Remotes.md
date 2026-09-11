# Remotes

Manage the rclone remotes the CLI operates on. Remote definitions and their
credentials live only in the rclone config — this CLI shells out to
`rclone listremotes` / `config create` / `config show` / `config delete` and
never stores credentials itself.

## Usage

```text
storage remotes             # list (alias: list-remotes)
storage remotes:add <name> <type> KEY=VALUE [...]
storage remotes:show <name> # secrets redacted
storage remotes:forget <name>
```

## Examples

```bash
# List configured remotes
storage remotes
storage list-remotes

# Show a remote's config (secret values are masked)
storage remotes:show myremote

# Add an S3 remote pointing at DigitalOcean Spaces
storage remotes:add myremote s3 provider=DigitalOcean \
  access_key_id=xxx secret_access_key=yyy endpoint=nyc3.digitaloceanspaces.com

# Add a local remote
storage remotes:add localbackup local

# Remove a remote (confirmation required unless --force)
storage remotes:forget myremote
storage remotes:forget myremote --force
```

## Behavior

- `remotes:add` validates the remote name, the backend type, and the
  `KEY=VALUE` form before calling `rclone config create`.
- `remotes:show` prints the config **with secret-looking keys masked**
  (`pass`, `secret`, `token`, `access_key`, …) so pasting output won't leak
  credentials.
- `remotes:forget` asks for confirmation unless `--force`, then runs
  `rclone config delete`.

## Safety

- The CLI only ever manipulates the rclone config (via the `RCLONE_CONFIG`
  environment variable when overridden). Tests point this at a temp file so
  they never touch `~/.config/rclone/rclone.conf`.
- Credentials are never stored in the app database, logs, or saved configs.

## Related

- [Interactive Wizard](Interactive-Wizard) — pick a remote interactively
- [Configuration](Configuration) — `STORAGE_CACHE_TTL` for remote-listing cache
- [List](List) — see a remote's buckets once configured