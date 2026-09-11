<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesStorageErrors;
use App\DTOs\StoragePath;
use App\Services\TransferService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

/**
 * @note Alias of "copy" using identical path syntax and semantics. Provided
 *       for interactive/legacy flow symmetry; both commands share
 *       TransferService::copy().
 */
#[AsCommand(name: 'copy-to')]
class CopyToCommand extends Command
{
    use HandlesStorageErrors;

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

            $this->describeLocations($source, $destination, 'COPY TO', $options->dryRun);

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
            ->setDescription('Alias of copy: copy files or a directory/prefix to another storage location')
            ->setHelp('Alias of "copy" using identical path syntax and semantics. See `storage help copy`.')
            ->addArgument('source', InputArgument::REQUIRED, 'Source storage path, e.g. do-spaces-nyc:my-data/videos/')
            ->addArgument('destination', InputArgument::REQUIRED, 'Destination storage path, e.g. do-spaces-sgp:backup/movies/');

        $this->addTransferOptions();
        $this->addQueueOption();
    }
}
