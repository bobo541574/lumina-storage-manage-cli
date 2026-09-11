<?php

declare(strict_types=1);

use App\Support\ExitCode;
use Illuminate\Support\Facades\Artisan;

test('list command shows a local directory with files and sub-directories', function () {
    $dir = sys_get_temp_dir().'/storage-list-'.bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);
    file_put_contents($dir.'/alpha.txt', str_repeat('a', 2048));
    mkdir($dir.'/sub', 0o777, true);
    file_put_contents($dir.'/sub/beta.txt', 'b');

    try {
        $this->artisan('list', ['path' => $dir])
            ->expectsOutputToContain('alpha.txt')
            ->expectsOutputToContain('sub/')
            ->expectsOutputToContain('1 directory')
            ->assertExitCode(0);
    } finally {
        rrmdir($dir);
    }
});

test('list command lists recursively', function () {
    $dir = sys_get_temp_dir().'/storage-list-'.bin2hex(random_bytes(4));
    mkdir($dir.'/nested/deeper', 0o777, true);
    file_put_contents($dir.'/nested/deeper/deep.txt', 'x');

    try {
        $this->artisan('list', ['path' => $dir, '--recursive' => true, '--type' => 'files'])
            ->expectsOutputToContain('deep.txt')
            ->expectsOutputToContain('1 file')
            ->assertExitCode(0);
    } finally {
        rrmdir($dir);
    }
});

test('list command shows files only when requested', function () {
    $dir = sys_get_temp_dir().'/storage-list-'.bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);
    file_put_contents($dir.'/only.txt', 'x');
    mkdir($dir.'/sub', 0o777, true);

    try {
        $this->artisan('list', ['path' => $dir, '--type' => 'files'])
            ->expectsOutputToContain('only.txt')
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('sub/');
    } finally {
        rrmdir($dir);
    }
});

test('list command reports a missing path with the not-found exit code', function () {
    $this->artisan('list', ['path' => '/definitely/not/a/real/storage/path-'.bin2hex(random_bytes(4))])
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

test('list command rejects an unparseable path with an invalid argument exit code', function () {
    $this->artisan('list', ['path' => 'remote:'])
        ->assertExitCode(2);
});

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            rrmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

test('list rejects an unknown --type instead of listing nothing', function () {
    Artisan::call('list', ['path' => sys_get_temp_dir(), '--type' => 'bogus']);

    $output = Artisan::output();

    expect($output)->toContain('Invalid --type "bogus"')
        ->and(Artisan::call('list', ['path' => sys_get_temp_dir(), '--type' => 'bogus']))->toBe(ExitCode::INVALID);
});

test('list rejects an unknown sort option', function () {
    $code = Artisan::call('list', ['path' => sys_get_temp_dir(), '--sort-file' => 'sideways']);

    expect($code)->toBe(ExitCode::INVALID)
        ->and(Artisan::output())->toContain('Invalid --sort-file "sideways"');
});
