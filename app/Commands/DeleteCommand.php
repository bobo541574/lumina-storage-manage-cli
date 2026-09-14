<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesStorageErrors;
use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\Exceptions\ObjectNotFoundException;
use App\Exceptions\StorageException;
use App\Services\StorageService;
use App\Support\ExitCode;
use App\Support\SizeFormatter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'delete')]
class DeleteCommand extends Command
{
    use HandlesStorageErrors;

    protected $description = 'Delete an object or everything under a prefix';

    protected $help = 'Delete a single object or all objects under a directory/prefix.

<options=bold>Usage</>:
  storage delete <path> [options]

<options=bold>Examples</>:
  storage delete do-spaces-nyc:my-data/report.pdf
  storage delete do-spaces-nyc:my-data/documents/

The interactive prompt shows the deletion scope (object count and total size)
and asks for confirmation; without a terminal use --force. Use --dry-run to
preview the scope without deleting anything.';

    public function __construct(private readonly StorageService $storage)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $path = StoragePath::fromString($this->argument('path'));
            $force = (bool) $this->option('force');
            $dryRun = (bool) $this->option('dry-run');

            // A destructive command must not quietly succeed on a typo: without
            // this, deleting a path that is not there exited 0 with "nothing to
            // delete", which reads exactly like a completed deletion.
            if (! $this->storage->exists($path)) {
                throw new ObjectNotFoundException(sprintf('Nothing found at: %s', $path->toDisplayString()));
            }

            // A dry run previews by actually running the deletion in dry-run
            // mode: one parallel rclone pass that reports the numbers it would
            // remove, with no up-front full listing. Counting from a listing
            // first only to have the deletion list the prefix again doubles the
            // work on a large prefix, and rclone's own count is more accurate.
            if ($dryRun) {
                $this->renderOperationHeader('DELETE', true);
                $this->renderDetail('Path', $path->toDisplayString());

                $options = new TransferOptions(dryRun: true, verbose: (bool) $this->option('verbose'));

                return $this->renderTransfer(
                    $this->storage->driverFor($path)->delete($path, $options),
                    'Deleted',
                    'deleted',
                    dryRun: true,
                );
            }

            [$count, $size] = $this->scopeFor($path);

            $this->renderOperationHeader('DELETE');
            $this->renderDetail('Path', $path->toDisplayString());
            $this->renderDetail('Objects', $count === null ? 'unknown' : (string) $count);

            if ($size !== null) {
                $this->renderDetail('Total size', SizeFormatter::human($size));
            }

            if (! $force && $count !== 0) {
                $this->newLine();

                $confirmed = $this->confirm(sprintf('Delete %s object(s). Continue?', $count ?? 'these'), false);

                if (! $confirmed) {
                    $this->line('Aborted.');

                    return ExitCode::SUCCESS;
                }
            }

            $options = new TransferOptions(verbose: (bool) $this->option('verbose'));
            $result = $this->storage->driverFor($path)->delete($path, $options);

            return $this->renderTransfer($result, 'Deleted', 'deleted');
        } catch (\Throwable $exception) {
            return $this->reportException($exception);
        }
    }

    /**
     * @return array{int|null, int|null} count and total size, or null when
     *                                   the scope cannot be enumerated.
     */
    private function scopeFor(StoragePath $path): array
    {
        try {
            $result = $this->storage->list($path, true, 'files');

            return [$result->count(), $result->totalSize()];
        } catch (ObjectNotFoundException) {
            return [0, 0];
        } catch (StorageException) {
            return [null, null];
        }
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete an object or all objects under a prefix')
            ->addArgument('path', InputArgument::REQUIRED, 'Storage path to delete, e.g. do-spaces-nyc:my-data/documents/')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the deletion confirmation prompt')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the deletion scope without deleting');
    }
}
