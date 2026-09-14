<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\PresentsOutput;
use App\DTOs\StoragePath;
use App\Models\SavedConfig;
use App\Support\ExitCode;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Persist a transfer as a reusable configuration profile.
 *
 * Restores the legacy "saved configurations" capability (multi-remote-transfer.sh).
 */
#[AsCommand(name: 'configs:save')]
class ConfigsSaveCommand extends Command
{
    use PresentsOutput;

    protected $description = 'Save a transfer as a reusable configuration profile';

    protected $help = 'Store an operation as a named configuration profile for later replay.

<options=bold>Usage</>:
  storage configs:save <name> <source> [<destination>] [options]

<options=bold>Examples</>:
  storage configs:save weekly-backup do-spaces-nyc:my-data/ do-spaces-sgp:backup/ --operation=copy
  storage configs:save cleanup tmp/ --operation=delete

Saving an existing name updates the profile. Load a profile into the wizard
with `storage wizard --config=<name>`.';

    private const OPERATIONS = ['list', 'download', 'upload', 'copy', 'move', 'rename', 'duplicate', 'copy-to', 'move-to', 'visibility', 'delete'];

    public function handle(): int
    {
        $name = $this->argument('name');
        $source = $this->argument('source');
        $destination = $this->argument('destination');
        $operation = $this->option('operation');

        if ($operation !== null && ! in_array($operation, self::OPERATIONS, true)) {
            $this->renderError(sprintf('Unknown operation "%s". Allowed: %s.', $operation, implode(', ', self::OPERATIONS)));

            return ExitCode::INVALID;
        }

        try {
            $sourcePath = StoragePath::fromString($source);

            if ($destination !== null) {
                $destinationPath = StoragePath::fromString($destination);
            }
        } catch (\Throwable $e) {
            $this->renderError($e->getMessage());

            return ExitCode::INVALID;
        }

        $options = [
            'overwrite' => $this->option('overwrite'),
            'recursive' => $this->option('recursive'),
        ];

        try {
            $config = SavedConfig::query()->updateOrCreate(
                ['name' => $name],
                [
                    'storage' => $sourcePath->isRemote() ? (string) $sourcePath->remote() : 'local',
                    'bucket' => $sourcePath->bucket(),
                    'operation' => $operation,
                    'source' => $sourcePath->toRclonePath(),
                    'destination' => isset($destinationPath) ? $destinationPath->toRclonePath() : null,
                    'options' => $options,
                ],
            );
        } catch (\Throwable $e) {
            $this->renderError('Could not save configuration: '.$e->getMessage());

            return ExitCode::FAILURE;
        }

        $this->renderSuccess(sprintf('Saved configuration %s (operation: %s).', $config->name, $config->operation ?? 'none'));

        return ExitCode::SUCCESS;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Profile name')
            ->addArgument('source', InputArgument::REQUIRED, 'Source storage path')
            ->addArgument('destination', InputArgument::OPTIONAL, 'Destination storage path')
            ->addOption('operation', null, InputOption::VALUE_REQUIRED, 'Operation this profile represents')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Record overwrite=true for this profile')
            ->addOption('recursive', null, InputOption::VALUE_NONE, 'Record recursive=true for this profile');

        parent::configure();
    }
}
