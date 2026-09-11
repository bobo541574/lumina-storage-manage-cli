<?php

declare(strict_types=1);

namespace App\Support;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Application logger for storage operations.
 *
 * Backed by the Laravel/Illuminate logging component (Monolog). Each
 * invocation appends structured lines to a rotating file (storage.log) inside
 * the configured directory; the `~` prefix is expanded to the user's home.
 *
 * Credentials must never be passed to this logger.
 */
final class StorageLogger
{
    /** @var array<string, Logger> */
    private array $loggers = [];

    public function __construct(
        string $directory,
        private readonly int $maxFiles = 14,
        string $level = 'info',
    ) {
        $this->directory = self::expandHome($directory);
        $this->level = self::levelFromString($level);
    }

    private readonly string $directory;

    private readonly Level $level;

    public function directory(): string
    {
        return $this->directory;
    }

    public function log(string $level, string $message, ?string $file = null): void
    {
        $this->loggerFor($file)->log(self::levelFromString($level), $message);
    }

    public function info(string $message, ?string $file = null): void
    {
        $this->log('info', $message, $file);
    }

    public function warning(string $message, ?string $file = null): void
    {
        $this->log('warning', $message, $file);
    }

    public function error(string $message, ?string $file = null): void
    {
        $this->log('error', $message, $file);
    }

    private function loggerFor(?string $file): Logger
    {
        $key = $file ?? '__default';

        if (! isset($this->loggers[$key])) {
            $name = $file === null ? 'storage.log' : ltrim($file, '/');
            $handler = new RotatingFileHandler(
                $this->directory.'/'.$name,
                $this->maxFiles,
                $this->level,
                bubble: true,
                filePermission: null,
                useLocking: true,
            );

            $this->loggers[$key] = new Logger($name, [$handler]);
        }

        return $this->loggers[$key];
    }

    public static function expandHome(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = $_SERVER['HOME'] ?? getenv('HOME');

            if (is_string($home) && $home !== '') {
                return $home.substr($path, 1);
            }
        }

        return $path;
    }

    private static function levelFromString(string $level): Level
    {
        $known = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        return in_array(strtolower($level), $known, true) ? Level::fromName($level) : Level::Info;
    }
}
