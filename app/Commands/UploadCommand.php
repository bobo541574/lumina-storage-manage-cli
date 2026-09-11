<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesStorageErrors;
use App\DTOs\StoragePath;
use App\Services\TransferService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'upload')]
class UploadCommand extends Command
{
    use HandlesStorageErrors;

    protected $description = 'Upload local files or directories to storage';

    protected $help = 'Upload local files or directory contents to a storage destination.

<options=bold>Usage</>:
  storage upload <source> <destination> [options]

<options=bold>Examples</>:
  storage upload /home/user/report.pdf do-spaces-nyc:my-data/report.pdf
  storage upload /home/user/backups/ do-spaces-nyc:my-data/backups/

A local directory is uploaded as prefix contents, preserving relative
structure. Use --overwrite to replace existing objects and --dry-run to
preview.';

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

            $this->describeLocations($source, $destination, 'UPLOAD', $options->dryRun);

            $this->trackProgress($this->transfers, $options);

            if (($queued = $this->maybeQueueOperation('upload', $source, $destination, $options)) !== null) {
                return $queued;
            }

            $result = $this->transfers->upload($source, $destination, $options);

            return $this->renderTransfer($result, 'Uploaded', 'uploaded', $options->dryRun);
        } catch (\Throwable $exception) {
            return $this->reportException($exception);
        }
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Upload files or a local directory to a remote storage path')
            ->addArgument('source', InputArgument::REQUIRED, 'Source local path, e.g. /home/user/documents/')
            ->addArgument('destination', InputArgument::REQUIRED, 'Destination remote storage path, e.g. do-spaces-nyc:my-data/documents/');

        $this->addTransferOptions();
        $this->addQueueOption();
    }
}
