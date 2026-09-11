<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\PresentsOutput;
use App\Exceptions\RemoteNotFoundException;
use App\Exceptions\StorageException;
use App\Services\RemoteManager;
use App\Support\ExitCode;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Delete a remote (including its credentials) from the rclone config.
 */
#[AsCommand(name: 'remotes:forget')]
class RemotesForgetCommand extends Command
{
    use PresentsOutput;

    protected $description = 'Delete a remote from the rclone config';

    protected $help = 'Remove a remote (and its credentials) from the rclone config via `rclone config delete`.

<options=bold>Usage</>:
  storage remotes:forget <name> [--force]

Deleting a remote removes its stored credentials from the rclone config.
The command asks for confirmation; use --force non-interactively.';

    public function handle(RemoteManager $remotes): int
    {
        $name = rtrim((string) $this->argument('name'), ':');
        $force = (bool) $this->option('force');

        if (! $force && ! $this->confirm(sprintf(
            'Delete remote "%s" and its credentials?',
            $name,
        ), false)) {
            $this->line('Cancelled.');

            return ExitCode::SUCCESS;
        }

        try {
            $remotes->remove($name);
        } catch (RemoteNotFoundException $e) {
            $this->renderError($e->getMessage());

            return ExitCode::SOURCE_NOT_FOUND;
        } catch (StorageException $e) {
            $this->renderError($e->getMessage());

            return ExitCode::FAILURE;
        }

        $this->line(sprintf('Remote <info>%s</info> deleted.', $name));

        return ExitCode::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Remote name (a trailing ":" is tolerated)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt');

        parent::configure();
    }
}
