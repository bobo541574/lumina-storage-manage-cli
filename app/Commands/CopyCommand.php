<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesStorageErrors;
use App\DTOs\StoragePath;
use App\Services\TransferService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'copy')]
class CopyCommand extends Command
{
    use HandlesStorageErrors;

    protected $description = 'Copy objects between storage locations';

    protected $help = 'Copy an object or the contents of a directory/prefix into the destination.

<options=bold>Usage</>:
  storage copy <source> <destination> [options]

<options=bold>Examples</>:
  storage copy do-spaces-nyc:my-data/report.pdf do-spaces-sgp:backup/report-final.pdf
  storage copy do-spaces-nyc:my-data/videos/ local:/srv/archive/

By default existing destination objects are kept; use --overwrite to replace
them. Directory sources copy their CONTENTS into the destination prefix while
preserving relative structure. Use --dry-run to preview without changing
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

            $this->describeLocations($source, $destination, 'COPY', $options->dryRun);

            $this->trackProgress($this->transfers, $options);

            if (($queued = $this->maybeQueueOperation('copy', $source, $destination, $options)) !== null) {
                return $queued;
            }

            $result = $this->transfers->copy($source, $destination, $options);

            return $this->renderTransfer($result, 'Copied', 'copied', $options->dryRun);
        } catch (\Throwable $exception) {
            return $this->reportException($exception);
        }
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Copy files or a directory/prefix to another storage location (no source deletion)')
            ->addArgument('source', InputArgument::REQUIRED, 'Source storage path, e.g. do-spaces-nyc:my-data/documents/')
            ->addArgument('destination', InputArgument::REQUIRED, 'Destination storage path, e.g. do-spaces-sgp:backup/documents/');

        $this->addTransferOptions();
        $this->addQueueOption();
    }
}
