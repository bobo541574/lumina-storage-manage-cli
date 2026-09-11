<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesStorageErrors;
use App\DTOs\StoragePath;
use App\Services\TransferService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'download')]
class DownloadCommand extends Command
{
    use HandlesStorageErrors;

    protected $description = 'Download objects from storage to a local path';

    protected $help = 'Download an object or prefix contents to a local destination.

<options=bold>Usage</>:
  storage download <source> <destination> [options]

<options=bold>Examples</>:
  storage download do-spaces-nyc:my-data/report.pdf /home/user/report.pdf
  storage download do-spaces-nyc:my-data/videos/ /home/user/videos/

A directory destination receives the contents of a remote prefix while
preserving relative structure. Use --overwrite to replace local files and
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

            $this->describeLocations($source, $destination, 'DOWNLOAD', $options->dryRun);

            $this->trackProgress($this->transfers, $options);

            if (($queued = $this->maybeQueueOperation('download', $source, $destination, $options)) !== null) {
                return $queued;
            }

            $result = $this->transfers->download($source, $destination, $options);

            return $this->renderTransfer($result, 'Downloaded', 'downloaded', $options->dryRun);
        } catch (\Throwable $exception) {
            return $this->reportException($exception);
        }
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Download files or a directory/prefix to a local path')
            ->addArgument('source', InputArgument::REQUIRED, 'Source remote storage path, e.g. do-spaces-nyc:my-data/documents/')
            ->addArgument('destination', InputArgument::REQUIRED, 'Destination local path, e.g. /home/user/backup/documents/');

        $this->addTransferOptions();
        $this->addQueueOption();
    }
}
