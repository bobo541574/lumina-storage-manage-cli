<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\PresentsOutput;
use App\Models\SavedConfig;
use App\Support\ExitCode;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Remove a saved configuration profile.
 */
#[AsCommand(name: 'configs:forget')]
class ConfigsForgetCommand extends Command
{
    use PresentsOutput;

    protected $description = 'Delete a saved configuration profile';

    protected $help = 'Delete a configuration profile created with `configs:save` or the wizard.

<options=bold>Usage</>:
  storage configs:forget <name>';

    public function handle(): int
    {
        $name = $this->argument('name');

        try {
            $deleted = SavedConfig::query()->where('name', $name)->delete();
        } catch (\Throwable $e) {
            $this->renderError('Could not delete configuration: '.$e->getMessage());

            return ExitCode::FAILURE;
        }

        if ($deleted === 0) {
            $this->renderError(sprintf('Configuration "%s" not found.', $name));

            return ExitCode::SOURCE_NOT_FOUND;
        }

        $this->renderSuccess(sprintf('Configuration %s deleted.', $name));

        return ExitCode::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Profile name');

        parent::configure();
    }
}
