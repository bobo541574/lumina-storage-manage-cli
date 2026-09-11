<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Remote/bucket discovery used by StorageService.
 */
interface RemoteDiscovery
{
    /**
     * Names of configured remotes (without trailing ":").
     *
     * @return array<int, string>
     */
    public function remotes(): array;

    /**
     * Bucket names visible at the remote root.
     *
     * @return array<int, string>
     */
    public function buckets(string $remote): array;
}
