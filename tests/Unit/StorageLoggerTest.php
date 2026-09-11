<?php

declare(strict_types=1);

use App\Support\StorageLogger;

test('writes log lines to the configured directory', function () {
    $dir = sys_get_temp_dir().'/storage-logger-'.bin2hex(random_bytes(4));
    $logger = new StorageLogger($dir);

    $logger->info('operation=copy status=success');
    $logger->error('boom');

    $logFile = $dir.'/storage-'.date('Y-m-d').'.log';
    expect($logFile)->toBeFile();

    $contents = file_get_contents($logFile);

    expect($contents)->toContain('INFO')
        ->and($contents)->toContain('operation=copy status=success')
        ->and($contents)->toContain('ERROR')
        ->and($contents)->toContain('boom');
});

test('writes to a custom file', function () {
    $dir = sys_get_temp_dir().'/storage-logger-'.bin2hex(random_bytes(4));

    (new StorageLogger($dir))->warning('x', 'custom.log');

    expect($dir.'/custom-'.date('Y-m-d').'.log')->toBeFile();
});
