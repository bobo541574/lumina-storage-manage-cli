<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * Safe rclone process execution.
 *
 * Arguments are always passed as an array — never interpolated into a shell
 * string — which prevents shell injection and correctly handles paths that
 * contain spaces or special characters.
 */
class RcloneProcess
{
    public function __construct(
        private readonly string $binary = 'rclone',
        private readonly int $timeout = 3600,
    ) {}

    /**
     * Execute rclone with the given arguments.
     *
     * When $onOutput is given it receives every chunk as it is produced
     * ("out"|"err", chunk), so long-running transfers can report progress
     * instead of appearing frozen until the process exits.
     *
     * @param  array<int, string>  $arguments
     */
    public function run(array $arguments, ?callable $onOutput = null): ProcessResult
    {
        // Explicitly pass the current environment. Symfony drops variables that
        // were set with putenv() but are not in $_SERVER (e.g. RCLONE_CONFIG),
        // which rclone needs to resolve its config file.
        $process = new Process(command: [$this->binary, ...$arguments], env: getenv());
        $process->setTimeout($this->timeout);

        if ($onOutput === null) {
            $process->run();
        } else {
            $process->run(static function (string $type, string $chunk) use ($onOutput): void {
                $onOutput($type === Process::ERR ? 'err' : 'out', $chunk);
            });
        }

        return new ProcessResult(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
        );
    }
}
