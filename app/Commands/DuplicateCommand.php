<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesStorageErrors;
use App\DTOs\StoragePath;
use App\Services\TransferService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'duplicate')]
class DuplicateCommand extends Command
{
    use HandlesStorageErrors;

    protected $description = 'Duplicate an object or prefix under a new name';

    protected $help = 'Copy an object or prefix to a new location while keeping the source intact.

<options=bold>Usage</>:
  storage duplicate <source> <destination> [options]

<options=bold>Examples</>:
  storage duplicate do-spaces-nyc:my-data/report.pdf do-spaces-nyc:my-data/report-copy.pdf
  storage duplicate do-spaces-nyc:my-data/docs/ do-spaces-nyc:my-data/docs-backup/

Unlike move/rename, the source is never modified. Use --overwrite to replace
existing destination objects and --dry-run to preview.';

    public function __construct(private readonly TransferService $transfers)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $source = StoragePath::fromString($this->argument('source'));
            $destination = StoragePath::fromString($this->argument('destination'));
            $options = $this->transferOptions();

            $this->describeLocations($source, $destination, 'DUPLICATE', $options->dryRun);

            $this->trackProgress($this->transfers, $options);

            if (($queued = $this->maybeQueueOperation('duplicate', $source, $destination, $options)) !== null) {
                return $queued;
            }

            $result = $this->transfers->copy($source, $destination, $options);

            return $this->renderTransfer($result, 'Duplicated', 'duplicated', $options->dryRun);
        } catch (\Throwable $exception) {
            return $this->reportException($exception);
        }
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Duplicate an object or prefix without deleting the source')
            ->addArgument('source', InputArgument::REQUIRED, 'Source storage path, e.g. do-spaces-nyc:my-data/report.pdf')
            ->addArgument('destination', InputArgument::REQUIRED, 'Duplicate storage path, e.g. do-spaces-nyc:my-data/report-copy.pdf');

        $this->addTransferOptions();
        $this->addQueueOption();
    }
}
