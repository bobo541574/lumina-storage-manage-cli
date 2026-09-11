<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Exceptions\PathParseException;

/**
 * Immutable value object representing a canonical storage location.
 *
 * Remote syntax:  <remote>:<bucket>/<path>
 * Local syntax:   filesystem path, optionally prefixed with "local:".
 *
 * A trailing slash marks an explicit directory/prefix intent.
 */
final class StoragePath
{
    private function __construct(
        private readonly StorageLocationType $type,
        private readonly ?string $remote,
        private readonly ?string $bucket,
        private readonly ?string $path,
        private readonly bool $directory,
    ) {}

    /**
     * Parse a canonical storage path from user input.
     *
     * @throws PathParseException when the value cannot be parsed safely.
     */
    public static function fromString(string $value): self
    {
        $value = trim($value);

        if ($value === '') {
            throw new PathParseException('Storage path cannot be empty.');
        }

        if (str_starts_with($value, 'local:')) {
            $local = self::expandHome(substr($value, strlen('local:')));

            if ($local === '') {
                throw new PathParseException('"local:" prefix requires a path.');
            }

            return self::fromLocalPath($local);
        }

        // The remainder may contain spaces: keys such as
        // "remote:bucket/My Reports/Q3 final.pdf" are ordinary on object
        // stores, and requiring \S+ here rejected them as unparseable.
        if (preg_match('/^([^:\\\\\/\s]+):(.+)$/', $value, $matches) === 1) {
            return self::fromRemoteParts($matches[1], $matches[2]);
        }

        if (str_contains($value, ':')) {
            throw new PathParseException(
                sprintf('Could not parse storage path "%s" as a remote. Use "local:" for local paths containing colons.', $value),
            );
        }

        return self::fromLocalPath(self::expandHome($value));
    }

    /**
     * Build a remote storage path from its parts.
     */
    public static function fromRemote(string $remote, ?string $bucket = null, ?string $path = null): self
    {
        if ($bucket === '') {
            throw new PathParseException('Remote bucket cannot be empty.');
        }

        $inflated = $bucket;

        if ($path !== null && $path !== '') {
            $inflated = rtrim($bucket, '/').'/'.ltrim($path, '/');
        }

        return self::fromRemoteParts($remote, $inflated);
    }

    /**
     * Build a local storage path.
     */
    public static function fromLocal(string $path): self
    {
        $path = self::expandHome(trim($path));

        if ($path === '') {
            throw new PathParseException('Local path cannot be empty.');
        }

        return self::fromLocalPath($path);
    }

    public function type(): StorageLocationType
    {
        return $this->type;
    }

    public function isRemote(): bool
    {
        return $this->type === StorageLocationType::Remote;
    }

    public function isLocal(): bool
    {
        return $this->type === StorageLocationType::Local;
    }

    public function remote(): ?string
    {
        return $this->remote;
    }

    public function bucket(): ?string
    {
        return $this->bucket;
    }

    /**
     * The path portion within the bucket (remote) or the filesystem path (local).
     * Null indicates a remote bucket root.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Whether the path explicitly targets a directory/prefix.
     */
    public function isDirectory(): bool
    {
        return $this->directory;
    }

    /**
     * Whether the path explicitly targets a file/object (not directory-tagged).
     */
    public function isFile(): bool
    {
        return ! $this->directory;
    }

    /**
     * Whether this is a remote directory/prefix (has a path portion).
     */
    public function isPrefix(): bool
    {
        return $this->isRemote() && $this->isDirectory() && ! $this->isBucketRoot();
    }

    /**
     * Whether this is the root of a remote bucket (<remote>:<bucket>).
     */
    public function isBucketRoot(): bool
    {
        return $this->isRemote() && $this->bucket !== null && $this->path === null;
    }

    /**
     * The filename portion of the object/local path, or null for directories.
     */
    public function filename(): ?string
    {
        if ($this->isDirectory() || $this->path === null) {
            return null;
        }

        return basename($this->path);
    }

    /**
     * Convert to a path usable by the storage backend (e.g. rclone).
     */
    public function toRclonePath(): string
    {
        if ($this->isLocal()) {
            return $this->path ?? '';
        }

        $prefix = $this->remote.':'.$this->bucket;

        if ($this->path === null || $this->path === '') {
            return $prefix;
        }

        return $prefix.'/'.ltrim($this->path, '/');
    }

    /**
     * Canonical display form used in output, logs and errors.
     */
    public function toDisplayString(): string
    {
        return $this->toRclonePath();
    }

    public function __toString(): string
    {
        return $this->toDisplayString();
    }

    /**
     * Return a copy marked as a directory/prefix, appending a trailing slash
     * where it makes sense.
     */
    public function asDirectory(): self
    {
        if ($this->isDirectory()) {
            return $this;
        }

        $path = $this->path;

        if ($path !== null && $path !== '' && ! str_ends_with($path, '/')) {
            $path .= '/';
        }

        return new self($this->type, $this->remote, $this->bucket, $path, true);
    }

    /**
     * Build a child path from a relative path segment, preserving the type.
     *
     * A trailing slash on the segment marks the child as a directory/prefix.
     */
    public function child(string $segment): self
    {
        $segment = ltrim($segment, '/');

        if ($segment === '') {
            throw new PathParseException('Child path cannot be empty.');
        }

        $directory = str_ends_with($segment, '/');
        $segment = rtrim($segment, '/');

        if ($this->isRemote()) {
            $path = ($this->path === null || $this->path === '')
                ? $segment
                : rtrim($this->path, '/').'/'.$segment;

            return self::fromRemote($this->remote, $this->bucket, $path.($directory ? '/' : ''));
        }

        if ($this->path === null) {
            throw new PathParseException('Cannot append a path to an empty local path.');
        }

        return self::fromLocal(rtrim($this->path, '/').'/'.$segment.($directory ? '/' : ''));
    }

    private static function fromRemoteParts(string $remote, string $remainder): self
    {
        if ($remote === '') {
            throw new PathParseException('Remote name cannot be empty.');
        }

        $pos = strpos($remainder, '/');

        if ($pos === false) {
            $bucket = $remainder;
            $path = null;
        } else {
            $bucket = substr($remainder, 0, $pos);
            $path = ltrim(substr($remainder, $pos + 1), '/');

            if ($path === '') {
                $path = null;
            }
        }

        if ($bucket === '') {
            throw new PathParseException(sprintf('Missing bucket in storage path "%s:%s".', $remote, $remainder));
        }

        $directory = $path === null || str_ends_with($path, '/');

        return new self(StorageLocationType::Remote, $remote, $bucket, $path, $directory);
    }

    private static function fromLocalPath(string $path): self
    {
        $directory = str_ends_with($path, '/');

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return new self(StorageLocationType::Local, null, null, $path, $directory);
    }

    private static function expandHome(string $path): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            $home = $_SERVER['HOME'] ?? getenv('HOME');

            if (is_string($home) && $home !== '') {
                return preg_replace('/^~(?=\/|$)/', $home, $path) ?? $path;
            }
        }

        return $path;
    }
}
