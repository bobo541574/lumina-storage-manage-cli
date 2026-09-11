<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\StorageLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Laravel logging channel factory backing the "storage" channel with the same
 * rotating-file handler used by StorageLogger, so Log::channel('storage')
 * writes to the same stream.
 */
final class CreateStorageLogger
{
    public function __invoke(array $config): Logger
    {
        $directory = rtrim(StorageLogger::expandHome((string) config('storage.logs.path', '~/.config/storage-cli/logs')), '/');

        $letter = ['debug' => 'debug', 'info' => 'info', 'notice' => 'notice', 'warning' => 'warning', 'error' => 'error', 'critical' => 'critical', 'alert' => 'alert', 'emergency' => 'emergency'];
        $wanted = strtolower((string) config('storage.logs.level', 'info'));
        $level = isset($letter[$wanted]) ? Level::fromName($wanted) : Level::Info;

        $handler = new RotatingFileHandler(
            $directory.'/storage.log',
            (int) config('storage.logs.max_files', 14),
            $level,
            bubble: true,
            filePermission: null,
            useLocking: true,
        );

        return new Logger('storage', [$handler]);
    }
}
