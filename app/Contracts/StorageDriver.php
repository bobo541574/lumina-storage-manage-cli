<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ListingResult;
use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\DTOs\TransferResult;

/**
 * Application-level storage semantics, independent of any backend.
 *
 * All path parameters are validated StoragePath value objects. Backend-specific
 * translation (e.g. rclone trailing-slash rules, S3 ACL naming) belongs in the
 * implementation, never in the command or service layer.
 */
interface StorageDriver
{
    public function name(): string;

    /**
     * Whether the configured backend is available (e.g. binary present).
     */
    public function isAvailable(): bool;

    /**
     * List the contents of a path. The path is expected to target a
     * directory/prefix. Directories and files can be filtered independently.
     *
     * With $withDirectorySizes, each directory entry carries the total size of
     * every object stored under it (a recursive sum), not just its children.
     */
    public function list(StoragePath $path, bool $recursive = false, bool $directories = true, bool $files = true, bool $withDirectorySizes = false): ListingResult;

    /**
     * Whether an object/file/prefix exists at the given path.
     */
    public function exists(StoragePath $path): bool;

    /**
     * Remote -> Local fetch of a single object, prefix, or bucket.
     */
    public function download(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult;

    /**
     * Local -> Remote push of a single object, directory, or bucket.
     */
    public function upload(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult;

    /**
     * Copy a single object/prefix from one location to another.
     */
    public function copy(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult;

    /**
     * Move a single object/prefix from one location to another.
     */
    public function move(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult;

    /**
     * Rename a single object/prefix within the same location.
     */
    public function rename(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult;

    /**
     * Delete an object or the contents of a prefix.
     */
    public function delete(StoragePath $path, TransferOptions $options = new TransferOptions): TransferResult;

    /**
     * Set the visibility (ACL) of an object, or recursively of all objects
     * under a prefix.
     *
     * @param  string  $visibility  Application-level value, e.g. "private"|"public".
     */
    public function visibility(StoragePath $path, string $visibility, TransferOptions $options = new TransferOptions): TransferResult;
}
