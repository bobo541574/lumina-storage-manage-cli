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
 * @note Alias of "move" using identical path syntax and semantics. Both
 *       commands share TransferService::move() (copy, verify, then delete
 *       source).
 */
#[AsCommand(name: 'move-to')]
class MoveToCommand extends Command
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

            $this->describeLocations($source, $destination, 'MOVE TO', $options->dryRun);

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
            ->setDescription('Alias of move: move files or a directory/prefix (copy, verify, then delete source)')
            ->setHelp('Alias of "move" using identical path syntax and semantics. See `storage help move`.')
            ->addArgument('source', InputArgument::REQUIRED, 'Source storage path, e.g. do-spaces-nyc:my-data/videos/')
            ->addArgument('destination', InputArgument::REQUIRED, 'Destination storage path, e.g. do-spaces-sgp:archive/videos/');

        $this->addTransferOptions();
        $this->addQueueOption();
    }
}
