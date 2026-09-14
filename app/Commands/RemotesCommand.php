<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\PresentsOutput;
use App\Services\RemoteManager;
use App\Support\ExitCode;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * List the rclone remotes defined in the rclone config (rclone.conf).
 */
#[AsCommand(name: 'remotes', aliases: ['list-remotes'])]
class RemotesCommand extends Command
{
    use PresentsOutput;

    protected $description = 'List configured rclone remotes';

    protected $help = 'List the remotes currently defined in the rclone config (rclone.conf / RCLONE_CONFIG).

<options=bold>Usage</>:
  storage remotes
  storage list-remotes

Remotes are managed by rclone; this command only lists them. Add and remove
remotes with `remotes:add` and `remotes:forget`.';

    public function handle(RemoteManager $remotes): int
    {
        try {
            $names = $remotes->list();
        } catch (\Throwable $e) {
            $this->renderError('Could not list remotes: '.$e->getMessage());

            return ExitCode::FAILURE;
        }

        $this->renderOperationHeader('REMOTES');

        if ($names === []) {
            $this->renderInfo('No remotes configured.');

            return ExitCode::SUCCESS;
        }

        $this->table(['Remote'], array_map(fn (string $name): array => [$name.':'], $names));

        return ExitCode::SUCCESS;
    }
}
