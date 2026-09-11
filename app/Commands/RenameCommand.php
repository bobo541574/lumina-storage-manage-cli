<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesStorageErrors;
use App\DTOs\StoragePath;
use App\Services\TransferService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'rename')]
class RenameCommand extends Command
{
    use HandlesStorageErrors;

    protected $description = 'Rename or relocate an object or prefix';

    protected $help = 'Rename a single object or relocate a prefix to a new location.

<options=bold>Usage</>:
  storage rename <source> <destination> [options]

<options=bold>Examples</>:
  storage rename do-spaces-nyc:my-data/report.pdf do-spaces-nyc:my-data/report-final.pdf
  storage rename do-spaces-nyc:my-data/docs/ do-spaces-nyc:my-data/archive/docs/

Source objects are removed only after they are copied and verified in the new
location. Use --overwrite to replace existing destination objects and
--dry-run to preview.';

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

            $this->describeLocations($source, $destination, 'RENAME', $options->dryRun);

            $this->trackProgress($this->transfers, $options);

            if (($queued = $this->maybeQueueOperation('rename', $source, $destination, $options)) !== null) {
                return $queued;
            }

            $result = $this->transfers->move($source, $destination, $options);

            return $this->renderTransfer($result, 'Renamed', 'renamed', $options->dryRun);
        } catch (\Throwable $exception) {
            return $this->reportException($exception);
        }
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Rename an object or prefix (copy, verify, then delete source)')
            ->addArgument('source', InputArgument::REQUIRED, 'Source storage path, e.g. do-spaces-nyc:my-data/report.pdf')
            ->addArgument('destination', InputArgument::REQUIRED, 'New storage path, e.g. do-spaces-nyc:my-data/report-final.pdf');

        $this->addTransferOptions();
        $this->addQueueOption();
    }
}
