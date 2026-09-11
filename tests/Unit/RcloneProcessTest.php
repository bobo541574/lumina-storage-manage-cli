<?php

declare(strict_types=1);

use App\Support\RcloneProcess;

test('passes arguments separately without shell interpolation', function () {
    $process = new RcloneProcess('/bin/echo', 10);

    $result = $process->run(['copy', 'hello world', 'path with spaces']);

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toContain('copy')
        ->and($result->stdout)->toContain('hello world')
        ->and($result->stdout)->toContain('path with spaces');
});

test('returns a failed result for a failing command', function () {
    $process = new RcloneProcess('/usr/bin/false', 10);

    $result = $process->run(['whatever']);

    expect($result->failed())->toBeTrue()
        ->and($result->exitCode)->not->toBe(0);
});
