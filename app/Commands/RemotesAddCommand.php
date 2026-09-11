<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\PresentsOutput;
use App\Exceptions\StorageException;
use App\Services\RemoteManager;
use App\Support\ExitCode;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Configure a new rclone remote. Credentials stay in the rclone config; they
 * are never stored by the application.
 */
#[AsCommand(name: 'remotes:add')]
class RemotesAddCommand extends Command
{
    use PresentsOutput;

    protected $description = 'Configure a new rclone remote';

    protected $help = 'Create a remote in the rclone config via `rclone config create`.

<options=bold>Usage</>:
  storage remotes:add <name> <type> [KEY=VALUE ...]

Certificates and secrets are stored by rclone in its own config
(rclone.conf / RCLONE_CONFIG); this command never persists or logs them.

<options=bold>Examples</>:
  storage remotes:add backup s3 access_key_id=xxx secret_access_key=yyy
  storage remotes:add warehouse local
  storage remotes:add drive google
  storage remotes:add NAS:ftp host=nas.local user=alice';

    public function handle(RemoteManager $remotes): int
    {
        $name = rtrim((string) $this->argument('name'), ':');
        $type = (string) $this->argument('type');

        try {
            $config = $this->parseConfig($this->argument('config') ?? []);

            $remotes->add($name, $type, $config);
        } catch (InvalidArgumentException $e) {
            $this->renderError($e->getMessage());

            return ExitCode::INVALID;
        } catch (StorageException $e) {
            $this->renderError($e->getMessage());

            return ExitCode::FAILURE;
        }

        $this->line(sprintf(
            'Configured remote <info>%s</info> (type: <info>%s</info>).',
            $name,
            $type,
        ));

        return ExitCode::SUCCESS;
    }

    /**
     * @param  array<int, string>  $entries
     * @return array<string, string>
     */
    private function parseConfig(array $entries): array
    {
        $config = [];

        foreach ($entries as $entry) {
            $separator = strpos($entry, '=');

            if ($separator === false) {
                throw new InvalidArgumentException(sprintf(
                    'Expected KEY=VALUE, got "%s".',
                    $entry,
                ));
            }

            $config[substr($entry, 0, $separator)] = substr($entry, $separator + 1);
        }

        return $config;
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Remote name (a trailing ":" is tolerated)')
            ->addArgument('type', InputArgument::REQUIRED, 'rclone backend type, e.g. s3, local, ftp, drive')
            ->addArgument('config', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Backend settings as KEY=VALUE pairs');

        parent::configure();
    }
}
