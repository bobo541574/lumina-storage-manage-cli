<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesStorageErrors;
use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\Services\VisibilityService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'visibility')]
class VisibilityCommand extends Command
{
    use HandlesStorageErrors;

    protected $description = 'Set or preview object visibility (private/public)';

    protected $help = 'Set or preview the visibility of a single object, directory, or prefix.

<options=bold>Usage</>:
  storage visibility <path> <visibility> [options]

<options=bold>Examples</>:
  storage visibility do-spaces-nyc:my-data/report.pdf public
  storage visibility do-spaces-nyc:my-data/documents/ private --recursive

Allowed application-level values: private, public. Use --recursive to apply to
everything under a prefix and --dry-run to preview which objects would change.';

    public function __construct(private readonly VisibilityService $visibility)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $path = StoragePath::fromString($this->argument('path'));
            $value = $this->argument('visibility');
            $options = new TransferOptions(
                dryRun: (bool) $this->option('dry-run'),
                verbose: (bool) $this->option('verbose'),
                recursive: (bool) $this->option('recursive'),
            );

            $this->renderOperationHeader('VISIBILITY', $options->dryRun);
            $this->renderDetail('Path', $path->toDisplayString());
            $this->renderDetail('Value', $value);

            $result = $this->visibility->apply($path, $value, $options);

            return $this->renderTransfer($result, 'Updated', 'updated', $options->dryRun);
        } catch (\Throwable $exception) {
            return $this->reportException($exception);
        }
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set the visibility/ACL of an object or prefix')
            ->addArgument('path', InputArgument::REQUIRED, 'Storage path, e.g. do-spaces-nyc:my-data/report.pdf')
            ->addArgument('visibility', InputArgument::REQUIRED, 'private or public (or a raw backend ACL value)')
            ->addOption('recursive', null, InputOption::VALUE_NONE, 'Apply recursively to all objects under the prefix')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate without changing storage');
    }
}
