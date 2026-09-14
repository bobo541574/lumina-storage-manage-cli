<?php

declare(strict_types=1);

namespace App\Drivers;

use App\Contracts\StorageDriver;
use App\DTOs\ListingEntry;
use App\DTOs\ListingResult;
use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\DTOs\TransferResult;
use App\DTOs\TransferStatus;
use App\Exceptions\ObjectNotFoundException;
use App\Exceptions\StorageException;
use App\Support\StorageLogger;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Pure-PHP local filesystem driver.
 */
final class LocalStorageDriver implements StorageDriver
{
    public function __construct(private readonly ?StorageLogger $logger = null) {}

    public function name(): string
    {
        return 'local';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function list(StoragePath $path, bool $recursive = false, bool $directories = true, bool $files = true, bool $withDirectorySizes = false): ListingResult
    {
        $absolute = $this->absolute($path);

        if (is_file($absolute)) {
            $entries = [new ListingEntry(basename($absolute), $path->path() ?? $absolute, (int) filesize($absolute), false, true)];

            return new ListingResult($entries);
        }

        if (! is_dir($absolute)) {
            throw new ObjectNotFoundException(sprintf('Local path not found: %s', $path->toDisplayString()));
        }

        $entries = [];

        $dirSizes = $directories && $withDirectorySizes ? $this->directorySizes($absolute) : [];

        $iterator = $recursive
            ? new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            )
            : new \DirectoryIterator($absolute);

        foreach ($iterator as $item) {
            if ($item->getFilename() === '.' || $item->getFilename() === '..') {
                continue;
            }

            $isDirectory = $item->isDir();

            if ($isDirectory && ! $directories) {
                continue;
            }

            if (! $isDirectory && ! $files) {
                continue;
            }

            $relative = $this->relativePath($absolute, $item->getPathname());

            $entries[] = new ListingEntry(
                name: $item->getFilename(),
                path: $relative,
                size: $isDirectory ? ($dirSizes[$relative] ?? 0) : (int) $item->getSize(),
                isDirectory: $isDirectory,
                isFile: ! $isDirectory,
            );
        }

        return new ListingResult($entries);
    }

    /**
     * Total size of every file stored under a directory, per relative
     * directory prefix (a full recursive walk; used for --size on list).
     *
     * @return array<string, int> relative directory path => bytes
     */
    private function directorySizes(string $absolute): array
    {
        $sizes = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $size = (int) $item->getSize();
            $dir = dirname($this->relativePath($absolute, $item->getPathname()));

            while ($dir !== '.' && $dir !== '/') {
                $sizes[$dir] = ($sizes[$dir] ?? 0) + $size;
                $dir = dirname($dir);
            }
        }

        return $sizes;
    }

    public function exists(StoragePath $path): bool
    {
        return file_exists($this->absolute($path));
    }

    public function download(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        return $this->copy($source, $destination, $options);
    }

    public function upload(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        return $this->copy($source, $destination, $options);
    }

    public function copy(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        $from = $this->absolute($source);
        $to = $this->absolute($destination);

        if (! file_exists($from) && ! is_link($from)) {
            $result = new TransferResult(TransferStatus::Failed, failed: 1, errors: ['Source not found: '.$source->toDisplayString()], source: $source, destination: $destination);
            $this->log($result, 'copy', $source, $destination, $options);

            return $result;
        }

        if (is_dir($from) && ! is_link($from)) {
            if ($options->dryRun) {
                $result = TransferResult::success($this->countFiles($from), source: $source, destination: $destination);
            } elseif (! is_dir($to) && ! @mkdir($to, 0o755, true)) {
                $result = new TransferResult(TransferStatus::Failed, failed: 1, errors: ["Could not create destination directory: $to"], source: $source, destination: $destination);
            } else {
                $result = $this->copyDirectoryTransfer($from, $to, $options, $source, $destination);
            }

            $this->log($result, 'copy', $source, $destination, $options);

            return $result;
        }

        if (! is_file($from)) {
            $result = new TransferResult(TransferStatus::Failed, failed: 1, errors: ['Source is not a regular file: '.$source->toDisplayString()], source: $source, destination: $destination);
            $this->log($result, 'copy', $source, $destination, $options);

            return $result;
        }

        $target = $destination->isDirectory() ? rtrim($to, '/').'/'.basename($from) : $to;

        if ($this->shouldSkipExisting($target, $options)) {
            $result = TransferResult::success(0, 1, $source, $destination);
            $this->log($result, 'copy', $source, $destination, $options);

            return $result;
        }

        if ($options->dryRun) {
            $result = TransferResult::success(1, source: $source, destination: $destination);
            $this->log($result, 'copy', $source, $destination, $options);

            return $result;
        }

        $parent = dirname($target);

        if (! is_dir($parent) && ! @mkdir($parent, 0o755, true)) {
            $result = new TransferResult(TransferStatus::Failed, failed: 1, errors: ["Could not create destination directory: $parent"], source: $source, destination: $destination);
            $this->log($result, 'copy', $source, $destination, $options);

            return $result;
        }

        $result = @copy($from, $target)
            ? TransferResult::success(1, source: $source, destination: $destination)
            : new TransferResult(TransferStatus::Failed, failed: 1, errors: ['Unable to copy: '.$source->toDisplayString()], source: $source, destination: $destination);
        $this->log($result, 'copy', $source, $destination, $options);

        return $result;
    }

    private function copyDirectoryTransfer(string $from, string $to, TransferOptions $options, StoragePath $source, StoragePath $destination): TransferResult
    {
        if ($options->dryRun) {
            $count = $this->countFiles($from);

            return TransferResult::success($count, source: $source, destination: $destination);
        }

        $outcome = $this->copyDirectory($from, $to, $options);

        // A file that could not be copied has to surface as a failure; counting
        // it as neither copied nor failed reported a clean success for a
        // transfer that silently lost data.
        if ($outcome['errors'] !== []) {
            return new TransferResult(
                $outcome['copied'] > 0 || $outcome['skipped'] > 0 ? TransferStatus::Partial : TransferStatus::Failed,
                $outcome['copied'],
                $outcome['skipped'],
                count($outcome['errors']),
                $outcome['errors'],
                $source,
                $destination,
            );
        }

        return TransferResult::success($outcome['copied'], $outcome['skipped'], $source, $destination);
    }

    /**
     * Copy, verify, then delete the source.
     *
     * Only files that were actually transferred are removed. Deleting the whole
     * source tree because "something was copied" destroyed the originals of
     * every file the run had skipped.
     */
    public function move(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        if ($options->dryRun) {
            return $this->copy($source, $destination, $options);
        }

        $from = $this->absolute($source);
        $to = $this->absolute($destination);

        if (is_dir($from) && ! is_link($from)) {
            $result = $this->copy($source, $destination, $options);

            if ($result->status === TransferStatus::Failed) {
                $this->log($result, 'move', $source, $destination, $options);

                return $result;
            }

            $errors = $result->errors;

            foreach ($this->copyDirectory($from, $to, $options, verifyOnly: true)['transferred'] as $sourceFile) {
                if (! @unlink($sourceFile)) {
                    $errors[] = 'Source could not be deleted: '.$sourceFile;
                }
            }

            $this->pruneEmptyDirectories($from);

            $result = $errors === []
                ? TransferResult::success($result->copied, $result->skipped, $source, $destination)
                : new TransferResult(TransferStatus::Partial, $result->copied, $result->skipped, count($errors), $errors, $source, $destination);

            $this->log($result, 'move', $source, $destination, $options);

            return $result;
        }

        $result = $this->copy($source, $destination, $options);

        if ($result->status === TransferStatus::Success && $result->copied > 0) {
            if (! @unlink($from)) {
                $result = new TransferResult(TransferStatus::Partial, $result->copied, $result->skipped, 1, ['Source could not be deleted: '.$source->toDisplayString()], $source, $destination);
                $this->log($result, 'move', $source, $destination, $options);

                return $result;
            }
        }

        $this->log($result, 'move', $source, $destination, $options);

        return $result;
    }

    public function rename(StoragePath $source, StoragePath $destination, TransferOptions $options = new TransferOptions): TransferResult
    {
        $from = $this->absolute($source);
        $to = $this->absolute($destination);

        if (! file_exists($from)) {
            $result = new TransferResult(TransferStatus::Failed, failed: 1, errors: ['Source not found: '.$from], source: $source, destination: $destination);
            $this->log($result, 'rename', $source, $destination, $options);

            return $result;
        }

        if (! is_dir(dirname($to)) && ! @mkdir(dirname($to), 0o755, true)) {
            $result = new TransferResult(TransferStatus::Failed, failed: 1, errors: ['Could not create destination directory: '.dirname($to)], source: $source, destination: $destination);
            $this->log($result, 'rename', $source, $destination, $options);

            return $result;
        }

        $result = @rename($from, $to)
            ? TransferResult::success(1, source: $source, destination: $destination)
            : new TransferResult(TransferStatus::Failed, failed: 1, errors: ['Rename failed'], source: $source, destination: $destination);
        $this->log($result, 'rename', $source, $destination, $options);

        return $result;
    }

    public function delete(StoragePath $path, TransferOptions $options = new TransferOptions): TransferResult
    {
        $target = $this->absolute($path);

        if (! file_exists($target)) {
            $result = TransferResult::success(0, source: $path);
            $this->log($result, 'delete', $path, null, $options);

            return $result;
        }

        // A dry run previews by counting what would be removed rather than by
        // a separate listing that the real deletion would repeat.
        if ($options->dryRun) {
            $count = is_dir($target) && ! is_link($target)
                ? $this->countFiles($target)
                : (is_file($target) ? 1 : 0);

            $result = TransferResult::success($count, source: $path);
            $this->log($result, 'delete', $path, null, $options);

            return $result;
        }

        // Success is whether every unlink/rmdir worked, not whether any files
        // were counted: removing an empty directory deletes zero files and is
        // still a completed delete.
        $succeeded = true;
        $deleted = $this->deletePath($target, $succeeded);

        if (! $succeeded) {
            $result = new TransferResult(
                $deleted > 0 ? TransferStatus::Partial : TransferStatus::Failed,
                $deleted,
                0,
                1,
                ['Unable to delete: '.$path->toDisplayString()],
                $path,
            );
            $this->log($result, 'delete', $path, null, $options);

            return $result;
        }

        $result = TransferResult::success($deleted, source: $path);
        $this->log($result, 'delete', $path, null, $options);

        return $result;
    }

    public function visibility(StoragePath $path, string $visibility, TransferOptions $options = new TransferOptions): TransferResult
    {
        $target = $this->absolute($path);

        if (! file_exists($target)) {
            throw new ObjectNotFoundException(sprintf('Local path not found: %s', $path->toDisplayString()));
        }

        $mode = $visibility === 'public'
            ? (is_dir($target) ? 0o755 : 0o644)
            : (is_dir($target) ? 0o700 : 0o600);

        if ($options->dryRun) {
            return TransferResult::success(0, 1, $path);
        }

        if (! @chmod($target, $mode)) {
            $result = new TransferResult(TransferStatus::Failed, failed: 1, errors: ['Unable to change permissions: '.$path->toDisplayString()], source: $path);
            $this->log($result, 'visibility', $path, null, $options);

            return $result;
        }

        $result = TransferResult::success(1, source: $path);
        $this->log($result, 'visibility', $path, null, $options);

        return $result;
    }

    private function log(TransferResult $result, string $operation, StoragePath $source, ?StoragePath $destination, TransferOptions $options): void
    {
        $status = match ($result->status) {
            TransferStatus::Success => 'success',
            TransferStatus::Partial => 'partial',
            TransferStatus::Failed => 'failed',
        };

        $parts = [
            sprintf('%s source=%s status=%s', $operation, $source->toDisplayString(), $status),
            'copied='.$result->copied,
            'skipped='.$result->skipped,
            'failed='.$result->failed,
        ];

        if ($destination !== null) {
            $parts[] = 'destination='.$destination->toDisplayString();
        }

        if ($options->dryRun) {
            $parts[] = 'dry_run=1';
        }

        $this->logger?->info(implode(' ', $parts));
    }

    private function absolute(StoragePath $path): string
    {
        if ($path->isRemote()) {
            throw new StorageException('A local storage path is required for the local driver.');
        }

        $value = $path->path() ?? '';

        if (str_starts_with($value, '/')) {
            return $value;
        }

        return getcwd().'/'.$value;
    }

    private function relativePath(string $base, string $pathname): string
    {
        $relative = substr($pathname, strlen(rtrim($base, '/')) + 1);

        return $relative === '' ? basename($pathname) : $relative;
    }

    /**
     * Recursively copy a directory's contents.
     *
     * With $verifyOnly the tree is only walked: nothing is written, and the
     * "transferred" list names the source files whose copy is present and
     * identical at the destination — the set a move is allowed to delete.
     *
     * @return array{copied: int, skipped: int, transferred: array<int, string>, errors: array<int, string>}
     */
    private function copyDirectory(string $from, string $to, TransferOptions $options, bool $verifyOnly = false): array
    {
        $copied = 0;
        $skipped = 0;
        $transferred = [];
        $errors = [];

        foreach (new \DirectoryIterator($from) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $dest = $to.'/'.$item->getFilename();

            if ($item->isDir()) {
                if (! $verifyOnly && ! is_dir($dest) && ! @mkdir($dest, 0o755, true)) {
                    $errors[] = 'Could not create destination directory: '.$dest;

                    continue;
                }

                $nested = $this->copyDirectory($item->getPathname(), $dest, $options, $verifyOnly);

                $copied += $nested['copied'];
                $skipped += $nested['skipped'];
                $transferred = [...$transferred, ...$nested['transferred']];
                $errors = [...$errors, ...$nested['errors']];

                continue;
            }

            if (! $item->isFile()) {
                continue;
            }

            if ($verifyOnly) {
                if (is_file($dest) && filesize($dest) === $item->getSize()) {
                    $transferred[] = $item->getPathname();
                }

                continue;
            }

            if ($this->shouldSkipExisting($dest, $options)) {
                $skipped++;

                continue;
            }

            if (@copy($item->getPathname(), $dest)) {
                $copied++;
                $transferred[] = $item->getPathname();

                continue;
            }

            $errors[] = 'Unable to copy: '.$item->getPathname();
        }

        return ['copied' => $copied, 'skipped' => $skipped, 'transferred' => $transferred, 'errors' => $errors];
    }

    /**
     * Remove directories that a move has emptied, deepest first, leaving any
     * directory that still holds skipped or failed files in place.
     */
    private function pruneEmptyDirectories(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (new \DirectoryIterator($directory) as $item) {
            if (! $item->isDot() && $item->isDir() && ! $item->isLink()) {
                $this->pruneEmptyDirectories($item->getPathname());
            }
        }

        $remaining = scandir($directory);

        if ($remaining !== false && count($remaining) === 2) {
            @rmdir($directory);
        }
    }

    private function shouldSkipExisting(string $target, TransferOptions $options): bool
    {
        return ! $options->overwrite && (is_file($target) || is_dir($target));
    }

    private function countFiles(string $directory): int
    {
        $count = 0;

        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isDot()) {
                continue;
            }

            if ($item->isDir()) {
                $count += $this->countFiles($item->getPathname());
            } elseif ($item->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Delete a file or a whole tree, returning the number of FILES removed and
     * reporting through $succeeded whether every removal worked.
     */
    private function deletePath(string $path, bool &$succeeded): int
    {
        if (is_dir($path) && ! is_link($path)) {
            $deleted = 0;

            foreach (new \DirectoryIterator($path) as $item) {
                if ($item->isDot()) {
                    continue;
                }

                $deleted += $this->deletePath($item->getPathname(), $succeeded);
            }

            if (! @rmdir($path)) {
                $succeeded = false;
            }

            return $deleted;
        }

        if (@unlink($path)) {
            return 1;
        }

        $succeeded = false;

        return 0;
    }
}
