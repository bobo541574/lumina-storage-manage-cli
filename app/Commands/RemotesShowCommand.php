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

/**
 * Show the (redacted) configuration of a single rclone remote.
 */
#[AsCommand(name: 'remotes:show')]
class RemotesShowCommand extends Command
{
    use PresentsOutput;

    protected $description = 'Show the configuration of one remote';

    protected $help = 'Print the rclone configuration section for a single remote.

<options=bold>Usage</>:
  storage remotes:show <name>

Secret values (passwords, tokens, access keys, ...) are redacted in the
output. Credentials never leave the rclone config.';

    public function handle(RemoteManager $remotes): int
    {
        $name = rtrim((string) $this->argument('name'), ':');

        try {
            $config = $remotes->show($name);
        } catch (RemoteNotFoundException $e) {
            $this->renderError($e->getMessage());

            return ExitCode::SOURCE_NOT_FOUND;
        } catch (StorageException $e) {
            $this->renderError($e->getMessage());

            return ExitCode::FAILURE;
        }

        $this->line(trim($config));

        return ExitCode::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Remote name (a trailing ":" is tolerated)');

        parent::configure();
    }
}
