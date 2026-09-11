<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StorageDriver;
use App\Drivers\LocalStorageDriver;
use App\Drivers\RcloneStorageDriver;
use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\DTOs\TransferResult;
use App\DTOs\TransferStatus;
use App\Exceptions\ObjectNotFoundException;
use App\Exceptions\StorageException;

/**
 * Application-level transfer orchestration.
 *
 * Enforces the mandatory directory semantics: copying a directory/prefix moves
 * its CONTENTS into the destination prefix while preserving relative structure,
 * never nesting the source name inside the destination. Cross-storage transfers
 * (remote<->remote, remote<->local, local<->local) all flow through the same
 * path handling here and are executed by the appropriate driver.
 */
class TransferService
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly RcloneStorageDriver $rclone,
        private readonly LocalStorageDriver $local,
    ) {}

    public function copy(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        $sourceIsDirectory = $this->sourceIsDirectory($source);
        $destination = $sourceIsDirectory ? $destination->asDirectory() : $destination;

        $this->assertSourceExists($source);

        // Only a dry run predicts counts from a listing; a real run reports the
        // numbers the driver actually observed, never an estimate made before
        // the transfer started.
        if ($options->dryRun) {
            $expected = $this->expectedCounts($source, $destination, $sourceIsDirectory, $options);

            return TransferResult::success($expected['copied'], $expected['skipped'], $source, $destination);
        }

        return $this->transferDriver($source, $destination)->copy($source, $destination, $options);
    }

    public function download(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        return $this->copy($source, $destination, $options);
    }

    public function upload(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        return $this->copy($source, $destination, $options);
    }

    /**
     * Move with copy -> verify -> delete semantics. Only objects that were
     * copied and verified are deleted from the source; failed objects remain.
     */
    public function move(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        $sourceIsDirectory = $this->sourceIsDirectory($source);
        $destination = $sourceIsDirectory ? $destination->asDirectory() : $destination;

        $this->assertSourceExists($source);

        $driver = $this->transferDriver($source, $destination);

        // rclone already implements copy -> verify -> delete per object, in one
        // process, in parallel and server-side where the backend supports it.
        // Driving it object-by-object from here would spawn several subprocesses
        // per object — tens of thousands of them for a large prefix.
        if ($driver instanceof RcloneStorageDriver) {
            if ($options->dryRun) {
                $expected = $this->expectedCounts($source, $destination, $sourceIsDirectory, $options);

                return TransferResult::success($expected['copied'], $expected['skipped'], $source, $destination);
            }

            return $driver->move($source, $destination, $options);
        }

        $objects = $sourceIsDirectory
            ? $this->sourceObjects($source)
            : [$source->filename() ?? basename((string) $source->toDisplayString())];

        if ($objects === []) {
            return TransferResult::success(source: $source, destination: $destination);
        }

        $copied = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($objects as $relative) {
            $sourceObject = $sourceIsDirectory ? $source->child($relative) : $source;
            $destinationObject = $sourceIsDirectory || $destination->isDirectory()
                ? $destination->child($relative)
                : $destination;

            if (! $options->overwrite && $this->storage->exists($destinationObject)) {
                $skipped++;

                continue;
            }

            if ($options->dryRun) {
                $copied++;

                continue;
            }

            $copyResult = $this->transferDriver($sourceObject, $destinationObject)->copy($sourceObject, $destinationObject, $options);

            if ($copyResult->status !== TransferStatus::Success) {
                $failed++;
                $errors = [...$errors, ...$copyResult->errors];

                continue;
            }

            if (! $this->storage->exists($destinationObject)) {
                $failed++;
                $errors[] = 'Verification failed: destination does not exist after copy: '.$destinationObject->toDisplayString();

                continue;
            }

            $deleteResult = $this->storage->driverFor($sourceObject)->delete($sourceObject, $options);

            if ($deleteResult->status !== TransferStatus::Success) {
                $failed++;
                $errors[] = 'Source could not be deleted: '.$sourceObject->toDisplayString();
            } else {
                $copied++;
            }
        }

        $status = match (true) {
            $failed === 0 => TransferStatus::Success,
            $copied > 0 || $skipped > 0 => TransferStatus::Partial,
            default => TransferStatus::Failed,
        };

        return new TransferResult($status, $copied, $skipped, $failed, $errors, $source, $destination);
    }

    /**
     * Register (or clear) a handler that receives live rclone counters while a
     * transfer is running, so the command layer can render progress.
     */
    public function reportProgress(?callable $handler): void
    {
        $this->rclone->onProgress($handler);
    }

    /**
     * The rclone driver handles any pair where at least one end is a remote;
     * pure local-to-local transfers are handled by the local filesystem driver.
     */
    public function transferDriver(StoragePath $source, StoragePath $destination): StorageDriver
    {
        if ($source->isLocal() && $destination->isLocal()) {
            return $this->local;
        }

        return $this->rclone;
    }

    private function sourceIsDirectory(StoragePath $source): bool
    {
        if ($source->isDirectory() || $source->isBucketRoot()) {
            return true;
        }

        if ($source->isLocal() && $source->path() !== null) {
            $absolute = $this->localPath($source);

            return is_dir($absolute);
        }

        return false;
    }

    /**
     * Fail fast on a source that is not there. Without this a mistyped remote
     * prefix reports a successful no-op instead of a not-found exit code.
     */
    private function assertSourceExists(StoragePath $source): void
    {
        if ($source->isLocal()) {
            $absolute = $this->localPath($source);

            if (! file_exists($absolute) && ! is_link($absolute)) {
                throw new ObjectNotFoundException(sprintf('Source not found: %s', $source->toDisplayString()));
            }

            return;
        }

        if (! $this->storage->exists($source)) {
            throw new ObjectNotFoundException(sprintf('Source not found: %s', $source->toDisplayString()));
        }
    }

    /**
     * @return array{copied: int, skipped: int}
     */
    private function expectedCounts(StoragePath $source, StoragePath $destination, bool $sourceIsDirectory, TransferOptions $options): array
    {
        if (! $sourceIsDirectory) {
            $exists = ! $options->overwrite && $this->storage->exists($destination);

            return $exists ? ['copied' => 0, 'skipped' => 1] : ['copied' => 1, 'skipped' => 0];
        }

        $relative = $this->sourceObjects($source);
        $total = count($relative);

        if ($options->overwrite) {
            return ['copied' => $total, 'skipped' => 0];
        }

        $existing = $this->existingObjects($destination);
        $skipped = count(array_intersect($relative, $existing));

        return ['copied' => max(0, $total - $skipped), 'skipped' => $skipped];
    }

    /**
     * @return array<int, string>
     */
    private function sourceObjects(StoragePath $source): array
    {
        try {
            $result = $this->storage->list($source, true, 'files');
        } catch (StorageException) {
            return [];
        }

        return array_map(static fn ($entry): string => $entry->path, $result->entries);
    }

    /**
     * @return array<int, string>
     */
    private function existingObjects(StoragePath $destination): array
    {
        try {
            $result = $this->storage->list($destination, true, 'files');
        } catch (StorageException) {
            return [];
        }

        return array_map(static fn ($entry): string => $entry->path, $result->entries);
    }

    private function localPath(StoragePath $path): string
    {
        $value = $path->path() ?? '';

        if ($value === '' || str_starts_with($value, '/')) {
            return $value;
        }

        return getcwd().'/'.$value;
    }
}
