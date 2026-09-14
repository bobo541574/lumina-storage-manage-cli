<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RemoteDiscovery;
use App\Contracts\StorageDriver;
use App\Drivers\LocalStorageDriver;
use App\DTOs\ListingResult;
use App\DTOs\StorageLocationType;
use App\DTOs\StoragePath;
use Illuminate\Contracts\Cache\Repository;

/**
 * Application-level orchestration over storage drivers.
 */
class StorageService
{
    public function __construct(
        private readonly RemoteDiscovery $rclone,
        private readonly LocalStorageDriver $local,
        private readonly ?Repository $cache = null,
        private readonly int $cacheTtl = 300,
    ) {}

    public function driverFor(StoragePath $path): StorageDriver
    {
        return match ($path->type()) {
            StorageLocationType::Remote => $this->rclone,
            StorageLocationType::Local => $this->local,
        };
    }

    /**
     * List the contents of a directory/prefix.
     *
     * @param  string  $type  all|dirs|files
     */
    public function list(StoragePath $path, bool $recursive = false, string $type = 'all', bool $withDirectorySizes = false): ListingResult
    {
        $includeDirs = $type === 'all' || $type === 'dirs';
        $includeFiles = $type === 'all' || $type === 'files';

        return $this->driverFor($path)->list($path, $recursive, $includeDirs, $includeFiles, $withDirectorySizes);
    }

    public function exists(StoragePath $path): bool
    {
        return $this->driverFor($path)->exists($path);
    }

    /**
     * Names of configured rclone remotes.
     *
     * @return array<int, string>
     */
    public function remotes(): array
    {
        return $this->rememberCached('remotes', function (): array {
            return $this->rclone->remotes();
        });
    }

    /**
     * Bucket names visible at the remote root.
     *
     * @return array<int, string>
     */
    public function buckets(string $remote): array
    {
        return $this->rememberCached('buckets.'.$remote, function () use ($remote): array {
            return $this->rclone->buckets($remote);
        });
    }

    /**
     * @return array<int, string>
     */
    private function rememberCached(string $key, callable $callback): array
    {
        return $this->cacheTtl > 0 && $this->cache !== null
            ? $this->cache->remember('storage.'.$key, $this->cacheTtl, $callback)
            : $callback();
    }
}
