<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RemoteNotFoundException;
use App\Exceptions\StorageException;
use App\Support\RcloneProcess;
use InvalidArgumentException;

/**
 * Create, list and remove rclone remotes.
 *
 * Remote definitions live in the rclone config (rclone.conf / RCLONE_CONFIG),
 * never in the application database. No credential value is ever logged or
 * stored outside rclone.
 */
final class RemoteManager
{
    private const SECRET_KEY_PATTERN = '/pass|secret|token|access_key/i';

    public function __construct(private readonly RcloneProcess $process) {}

    /**
     * Names of all configured remotes (without trailing ":").
     *
     * @return array<int, string>
     */
    public function list(): array
    {
        $result = $this->process->run(['listremotes']);

        if ($result->failed()) {
            throw new StorageException('Could not list remotes: '.$result->combine());
        }

        $remotes = [];

        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && str_ends_with($line, ':')) {
                $remotes[] = substr($line, 0, -1);
            }
        }

        return $remotes;
    }

    /**
     * Configure a new remote via `rclone config create`.
     *
     * @param  string  $name  remote name (a trailing ":" is tolerated)
     * @param  array<string, string>  $config  arbitrary key/value settings
     */
    public function add(string $name, string $type, array $config = []): void
    {
        $name = rtrim($name, ':');

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid remote name "%s". Use letters, digits, "_", "-" or ".".',
                $name,
            ));
        }

        if (preg_match('/^[A-Za-z0-9_.-]+$/', $type) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid remote type "%s".', $type));
        }

        if (in_array($name, $this->list(), true)) {
            throw new StorageException(sprintf('Remote "%s" is already configured.', $name));
        }

        $arguments = ['config', 'create', $name, $type];

        foreach ($config as $key => $value) {
            $arguments[] = $key;
            $arguments[] = $value;
        }

        $result = $this->process->run($arguments);

        if ($result->failed() || ! in_array($name, $this->list(), true)) {
            throw new StorageException('Could not configure remote: '.$result->combine());
        }
    }

    /**
     * Redacted configuration section for one remote.
     */
    public function show(string $name): string
    {
        $name = rtrim($name, ':');

        if (! in_array($name, $this->list(), true)) {
            throw new RemoteNotFoundException(sprintf('Remote "%s" is not configured.', $name));
        }

        $result = $this->process->run(['config', 'show', $name]);

        if ($result->failed()) {
            throw new StorageException('Could not show remote: '.$result->combine());
        }

        return $this->redact($result->stdout);
    }

    /**
     * Replace values of secret-looking keys (password, token, secret, access
     * key, ...) so credentials never appear in command output.
     */
    private function redact(string $config): string
    {
        return preg_replace_callback(
            '/^([A-Za-z0-9_-]+)\s*=\s*(.+)$/m',
            function (array $match): string {
                $value = preg_match(self::SECRET_KEY_PATTERN, $match[1]) ? '***' : $match[2];

                return $match[1].' = '.$value;
            },
            $config,
        ) ?? $config;
    }

    /**
     * Delete a remote via `rclone config delete`.
     */
    public function remove(string $name): void
    {
        $name = rtrim($name, ':');

        if (! in_array($name, $this->list(), true)) {
            throw new RemoteNotFoundException(sprintf('Remote "%s" is not configured.', $name));
        }

        $result = $this->process->run(['config', 'delete', $name]);

        if ($result->failed() || in_array($name, $this->list(), true)) {
            throw new StorageException('Could not delete remote: '.$result->combine());
        }
    }
}
