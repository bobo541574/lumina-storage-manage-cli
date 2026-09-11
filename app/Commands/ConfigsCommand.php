<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\PresentsOutput;
use App\Models\SavedConfig;
use App\Support\ExitCode;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * List the saved configuration profiles stored in the application database.
 */
#[AsCommand(name: 'configs', aliases: ['list-configs'])]
class ConfigsCommand extends Command
{
    use PresentsOutput;

    protected $description = 'List saved configuration profiles';

    protected $help = 'List the configuration profiles stored by `configs:save` or the wizard.

<options=bold>Usage</>:
  storage configs
  storage list-configs

Profiles capture an operation, source and optional destination so recurring
transfers can be replayed or preloaded into the wizard with --config.';

    public function handle(): int
    {
        try {
            $configs = SavedConfig::query()->orderBy('name')->get();
        } catch (\Throwable $e) {
            $this->renderError('Could not read saved configurations: '.$e->getMessage());

            return ExitCode::FAILURE;
        }

        if ($configs->isEmpty()) {
            $this->line('No saved configurations.');

            return ExitCode::SUCCESS;
        }

        $this->table(
            ['Name', 'Operation', 'Storage', 'Source', 'Destination'],
            $configs->map(fn (SavedConfig $config): array => [
                $config->name,
                $config->operation ?? '-',
                $config->storage,
                $config->source,
                $config->destination ?? '-',
            ]),
        );

        return ExitCode::SUCCESS;
    }
}
