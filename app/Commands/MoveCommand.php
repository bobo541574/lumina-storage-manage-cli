<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesStorageErrors;
use App\DTOs\StoragePath;
use App\Services\TransferService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'move')]
class MoveCommand extends Command
{
    use HandlesStorageErrors;

    protected $description = 'Move objects between storage locations';

    protected $help = 'Move an object or prefix contents to a new location using copy -> verify -> delete semantics.

<options=bold>Usage</>:
  storage move <source> <destination> [options]

<options=bold>Examples</>:
  storage move do-spaces-nyc:my-data/report.pdf do-spaces-sgp:backup/report-final.pdf
  storage move /srv/data/incoming local:/srv/data/archive/

Only objects that were copied and verified are removed from the source; failed
objects remain in place. By default existing destination objects are kept; use
--overwrite to replace them. Use --dry-run to preview without changing
anything.';

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

            $this->describeLocations($source, $destination, 'MOVE', $options->dryRun);

            $this->trackProgress($this->transfers, $options);

            if (($queued = $this->maybeQueueOperation('move', $source, $destination, $options)) !== null) {
                return $queued;
            }

            $result = $this->transfers->move($source, $destination, $options);

            return $this->renderTransfer($result, 'Moved', 'moved', $options->dryRun);
        } catch (\Throwable $exception) {
            return $this->reportException($exception);
        }
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Move files or a directory/prefix (copy, verify, then delete source)')
            ->addArgument('source', InputArgument::REQUIRED, 'Source storage path, e.g. do-spaces-nyc:my-data/documents/')
            ->addArgument('destination', InputArgument::REQUIRED, 'Destination storage path, e.g. do-spaces-sgp:archive/documents/');

        $this->addTransferOptions();
        $this->addQueueOption();
    }
}
