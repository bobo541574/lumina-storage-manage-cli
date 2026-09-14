<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\DTOs\TransferResult;
use App\DTOs\TransferStatus;
use App\Exceptions\BucketNotFoundException;
use App\Exceptions\ObjectNotFoundException;
use App\Exceptions\PathParseException;
use App\Exceptions\PathValidationException;
use App\Exceptions\RemoteNotFoundException;
use App\Exceptions\StorageException;
use App\Exceptions\VisibilityException;
use App\Jobs\ProcessTransferJob;
use App\Services\TransferService;
use App\Support\ExitCode;
use App\Support\RcloneStats;
use App\Support\SizeFormatter;
use Illuminate\Contracts\Queue\Queue;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared option definitions, error-to-exit-code mapping and result rendering
 * for storage commands.
 */
trait HandlesStorageErrors
{
    use PresentsOutput;

    private bool $progressActive = false;

    protected function transferOptions(): TransferOptions
    {
        return new TransferOptions(
            transfers: (int) $this->option('transfers'),
            retries: (int) $this->option('retries'),
            dryRun: (bool) $this->option('dry-run'),
            verbose: (bool) $this->option('verbose'),
            overwrite: (bool) $this->option('overwrite'),
            recursive: $this->hasOption('recursive') ? (bool) $this->option('recursive') : (bool) config('storage.defaults.recursive', false),
            progress: (bool) $this->option('progress'),
            // Falls back to storage.defaults.acl through the option's default,
            // the same way --transfers and --retries do. Null leaves the choice
            // to the driver, which then uses the destination remote's own ACL.
            acl: $this->option('acl'),
        );
    }

    protected function addTransferOptions(): void
    {
        // Note: --verbose is provided globally by Symfony; do not re-declare it.
        $defaults = config('storage.defaults', []);
        $this->addOption('transfers', null, InputOption::VALUE_REQUIRED, 'Number of parallel transfers', (string) ($defaults['transfers'] ?? 8))
            ->addOption('retries', null, InputOption::VALUE_REQUIRED, 'Retries on failure', (string) ($defaults['retries'] ?? 3))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate without modifying storage')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite existing objects')
            ->addOption('progress', null, InputOption::VALUE_NONE, 'Show live transfer progress')
            ->addOption('acl', null, InputOption::VALUE_REQUIRED, 'ACL for written objects: private, public, or a canned S3 ACL (default: the destination remote\'s own acl)', $defaults['acl'] ?? null);
    }

    /**
     * Queue-related option shared by transfer commands.
     */
    protected function addQueueOption(): void
    {
        $this->addOption('queue', null, InputOption::VALUE_NONE, 'Dispatch the operation as a queue job instead of running it now');
    }

    /**
     * Dispatch the operation as a queue job when --queue is present. Returns
     * the exit code when queued, null when the job should run in-process.
     */
    protected function maybeQueueOperation(
        string $operation,
        StoragePath $source,
        StoragePath $destination,
        TransferOptions $options,
        array $extra = [],
    ): ?int {
        if (! $this->option('queue')) {
            return null;
        }

        $job = new ProcessTransferJob(
            operation: $operation,
            source: $source->toRclonePath(),
            destination: $destination->toRclonePath(),
            options: array_merge([
                'transfers' => $options->transfers,
                'retries' => $options->retries,
                'dry_run' => $options->dryRun,
                'verbose' => $options->verbose,
                'overwrite' => $options->overwrite,
                'recursive' => $options->recursive,
                'progress' => $options->progress,
                'acl' => $options->acl,
            ], $extra),
        );

        $id = app(Queue::class)->push($job);

        $this->newLine();
        $this->renderInfo(sprintf(
            'Queued %s: %s -> %s%s',
            $operation,
            $source->toDisplayString(),
            $destination->toDisplayString(),
            $id !== null && $id !== true ? '  (job '.$id.')' : '',
        ));

        return ExitCode::SUCCESS;
    }

    protected function exitCodeFor(\Throwable $exception): int
    {
        return match (true) {
            $exception instanceof PathParseException,
            $exception instanceof PathValidationException => ExitCode::INVALID,
            $exception instanceof ObjectNotFoundException,
            $exception instanceof RemoteNotFoundException,
            $exception instanceof BucketNotFoundException => ExitCode::SOURCE_NOT_FOUND,
            $exception instanceof VisibilityException => ExitCode::VISIBILITY,
            $exception instanceof StorageException => ExitCode::FAILURE,
            default => ExitCode::FAILURE,
        };
    }

    protected function reportException(\Throwable $exception): int
    {
        $this->clearProgress();
        $this->newLine();
        $this->renderError($exception->getMessage());

        if ($this->option('verbose')) {
            $this->line('');
            $this->line('<fg=gray>'.get_class($exception).'</>');
            foreach ($exception->getTrace() as $frame) {
                if (isset($frame['file'])) {
                    $this->line(sprintf('<fg=gray>  %s:%d</>', $frame['file'], $frame['line']));
                }
            }
        }

        return $this->exitCodeFor($exception);
    }

    protected function renderTransfer(TransferResult $result, string $pastVerb, string $pluralVerb, bool $dryRun = false): int
    {
        $this->clearProgress();

        if ($result->total() === 0 && $result->status === TransferStatus::Success) {
            $this->newLine();

            // Nothing to do is an ordinary outcome, not a simulation and not a
            // failure — a DRY RUN badge here would misreport a real run.
            $dryRun
                ? $this->renderDryRun('No objects found. Nothing to '.$this->infinitiveFor($pastVerb).'.')
                : $this->renderInfo('No objects found. Nothing to '.$this->infinitiveFor($pastVerb).'.');

            return ExitCode::SUCCESS;
        }

        if ($dryRun) {
            $message = sprintf(
                'Would %s %d object%s%s. No changes made.',
                $this->infinitiveFor($pastVerb),
                $result->copied,
                $result->copied === 1 ? '' : 's',
                $result->skipped > 0 ? sprintf(', skip %d', $result->skipped) : '',
            );
            $this->newLine();
            $this->renderDryRun($message);

            // A dry run can fail too (an unreadable object while listing), and
            // that is a signal — report the failures and the exit code, not a
            // clean success.
            $this->renderErrorList($result->errors);

            return match ($result->status) {
                TransferStatus::Success => ExitCode::SUCCESS,
                TransferStatus::Partial => ExitCode::PARTIAL,
                TransferStatus::Failed => ExitCode::FAILURE,
            };
        }

        $this->newLine();

        $verb = $this->pastToPresent($pastVerb);

        // One status badge per run: a partial result renders PARTIAL even
        // though it also has failed counts, and a failure renders FAILED — never
        // both. The old code re-badged the status after the result line, so a
        // partial run flashed red then yellow.
        if ($result->status !== TransferStatus::Success) {
            $message = sprintf(
                '%s %d, skipped %d, failed %d object%s.%s',
                ucfirst($verb),
                $result->copied,
                $result->skipped,
                $result->failed,
                $result->failed === 1 ? '' : 's',
                $this->timing(),
            );

            $result->status === TransferStatus::Partial
                ? $this->renderPartial($message)
                : $this->renderFailed($message);
        } elseif ($result->skipped > 0) {
            $this->renderSuccess(sprintf(
                '%s %d, skipped %d object%s.%s',
                ucfirst($verb),
                $result->copied,
                $result->skipped,
                ($result->copied + $result->skipped) === 1 ? '' : 's',
                $this->timing(),
            ));
        } else {
            $this->renderSuccess(sprintf(
                '%s %d object%s.%s',
                ucfirst($verb),
                $result->copied,
                $result->copied === 1 ? '' : 's',
                $this->timing(),
            ));
        }

        $this->renderErrorList($result->errors);

        return match ($result->status) {
            TransferStatus::Success => ExitCode::SUCCESS,
            TransferStatus::Partial => ExitCode::PARTIAL,
            TransferStatus::Failed => ExitCode::FAILURE,
        };
    }

    /**
     * Show at most a handful of failures inline; the rest are in the log. A
     * wall of thousands of identical errors is not a usable error report.
     *
     * @param  array<int, string>  $errors
     */
    protected function renderErrorList(array $errors, int $limit = 5): void
    {
        if ($errors === []) {
            return;
        }

        $this->newLine();

        foreach (array_slice($errors, 0, $limit) as $error) {
            $this->renderError($error);
        }

        if (count($errors) > $limit) {
            $this->renderHint(sprintf('   … and %d more (see the log for the full list).', count($errors) - $limit));
        }
    }

    /**
     * Stream live counters while a transfer runs so a multi-thousand-object
     * operation does not look frozen. Only rendered on an interactive terminal.
     */
    protected function trackProgress(TransferService $transfers, TransferOptions $options): void
    {
        if (! $options->progress || ! $this->output->isDecorated()) {
            $transfers->reportProgress(null);

            return;
        }

        $transfers->reportProgress(function (RcloneStats $stats): void {
            $percent = $stats->progress();

            $this->progressActive = true;
            $this->output->write(sprintf(
                "\r  <fg=cyan>▸</>  %s / %s%s  ·  %d transferred  ·  %s   ",
                SizeFormatter::human($stats->bytes),
                SizeFormatter::human($stats->totalBytes),
                $percent === null ? '' : sprintf('  ·  %d%%', (int) round($percent * 100)),
                $stats->transfers,
                $this->elapsed() ?? '',
            ));
        });
    }

    /**
     * Erase the in-place progress line before anything else is printed.
     */
    protected function clearProgress(): void
    {
        if (! $this->progressActive) {
            return;
        }

        $this->progressActive = false;
        $this->output->write("\r".str_repeat(' ', 78)."\r");
    }

    private function timing(): string
    {
        $elapsed = $this->elapsed();

        return $elapsed === null ? '' : '  <fg=gray>('.$elapsed.')</>';
    }

    protected function describeLocations(StoragePath $source, StoragePath $destination, string $operation, bool $dryRun): void
    {
        $this->renderOperationHeader($operation, $dryRun);
        $this->renderDetail('Source', $source->toDisplayString());
        $this->renderDetail('Destination', $destination->toDisplayString());
    }

    private function infinitiveFor(string $pastVerb): string
    {
        return match (strtolower($pastVerb)) {
            'copied' => 'copy',
            'moved' => 'move',
            'deleted' => 'delete',
            'updated' => 'update',
            'renamed' => 'rename',
            'duplicated' => 'duplicate',
            'downloaded' => 'download',
            'uploaded' => 'upload',
            default => lcfirst($pastVerb),
        };
    }

    private function pastToPresent(string $pastVerb): string
    {
        return match (strtolower($pastVerb)) {
            'copied' => 'Copied',
            'moved' => 'Moved',
            'deleted' => 'Deleted',
            'updated' => 'Updated',
            'renamed' => 'Renamed',
            'duplicated' => 'Duplicated',
            'downloaded' => 'Downloaded',
            'uploaded' => 'Uploaded',
            default => $pastVerb,
        };
    }
}
