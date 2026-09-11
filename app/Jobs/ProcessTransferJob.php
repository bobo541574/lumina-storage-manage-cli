<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\Exceptions\StorageException;
use App\Services\StorageService;
use App\Services\TransferService;
use App\Services\VisibilityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Runs a storage operation on the queue. Created by the --queue flag on
 * transfer commands and reprocessed by `queue:work` / `retry`.
 *
 * Failed/partial operations throw so the job lands in failed_jobs and can be
 * re-dispatched with `retry`.
 */
class ProcessTransferJob implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    private const DESTINATION_OPERATIONS = ['copy', 'duplicate', 'move', 'rename', 'download', 'upload'];

    public function __construct(
        public readonly string $operation,
        public readonly string $source,
        public readonly ?string $destination,
        public readonly array $options = [],
    ) {}

    public function handle(
        TransferService $transfers,
        StorageService $storage,
        VisibilityService $visibility,
        LoggerInterface $log,
    ): void {
        if (in_array($this->operation, self::DESTINATION_OPERATIONS, true) && $this->destination === null) {
            throw new InvalidArgumentException(sprintf('Queued %s requires a destination path.', $this->operation));
        }

        $options = new TransferOptions(
            transfers: (int) ($this->options['transfers'] ?? 8),
            retries: (int) ($this->options['retries'] ?? 3),
            dryRun: (bool) ($this->options['dry_run'] ?? false),
            verbose: (bool) ($this->options['verbose'] ?? false),
            overwrite: (bool) ($this->options['overwrite'] ?? false),
            recursive: (bool) ($this->options['recursive'] ?? false),
            progress: (bool) ($this->options['progress'] ?? false),
            acl: ($this->options['acl'] ?? null) !== null ? (string) $this->options['acl'] : null,
        );

        $source = StoragePath::fromString($this->source);
        $destination = $this->destination !== null ? StoragePath::fromString($this->destination) : null;

        $result = match ($this->operation) {
            'copy', 'duplicate' => $transfers->copy($source, $destination, $options),
            'move', 'rename' => $transfers->move($source, $destination, $options),
            'download' => $transfers->download($source, $destination, $options),
            'upload' => $transfers->upload($source, $destination, $options),
            'delete' => $storage->driverFor($source)->delete($source, $options),
            'visibility' => $visibility->apply($source, (string) ($this->options['visibility'] ?? 'private'), $options),
            default => throw new InvalidArgumentException(sprintf('Unknown queued operation "%s".', $this->operation)),
        };

        if ($result->failed > 0) {
            throw new StorageException(sprintf(
                'Queued %s failed: %d object(s) failed (%s).',
                $this->operation,
                $result->failed,
                $result->errors === [] ? 'see logs' : implode('; ', array_slice($result->errors, 0, 3)),
            ));
        }

        $log->info(sprintf(
            'job operation=%s source=%s destination=%s status=success copied=%d skipped=%d',
            $this->operation,
            $source->toDisplayString(),
            $destination?->toDisplayString() ?? 'n/a',
            $result->copied,
            $result->skipped,
        ));
    }
}
