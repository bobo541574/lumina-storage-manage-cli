<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\DTOs\TransferResult;
use App\Exceptions\PathValidationException;

/**
 * Application-level visibility/ACL orchestration.
 *
 * The command layer only exposes "private" and "public" (plus raw backend ACL
 * values for legacy compatibility). Mapping to backend-specific ACL names is
 * the driver's responsibility.
 */
class VisibilityService
{
    public function __construct(private readonly StorageService $storage) {}

    public function apply(StoragePath $path, string $visibility, TransferOptions $options = new TransferOptions): TransferResult
    {
        $allowed = array_merge(
            array_keys(config('storage.visibility', [])),
            ['private', 'public', 'public-read', 'public-read-write', 'authenticated-read'],
        );

        if (! in_array($visibility, $allowed, true)) {
            throw new PathValidationException(sprintf(
                'Invalid visibility "%s". Valid values: private, public, public-read, public-read-write, authenticated-read.',
                $visibility,
            ));
        }

        return $this->storage->driverFor($path)->visibility($path, $visibility, $options);
    }
}
