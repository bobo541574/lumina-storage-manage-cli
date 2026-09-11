<?php

declare(strict_types=1);

use App\Support\ExitCode;

test('visibility sets public permissions on a local file', function () {
    $file = visibility_file();

    try {
        $this->artisan('visibility', ['path' => $file, 'visibility' => 'public'])
            ->expectsOutputToContain('Updated 1 object')
            ->assertExitCode(0);

        expect(decoct(fileperms($file) & 0o777))->toBe('644');
    } finally {
        @unlink($file);
    }
});

test('visibility sets private permissions on a local file', function () {
    $file = visibility_file();
    chmod($file, 0o644);

    try {
        $this->artisan('visibility', ['path' => $file, 'visibility' => 'private'])
            ->assertExitCode(0);

        expect(decoct(fileperms($file) & 0o777))->toBe('600');
    } finally {
        @unlink($file);
    }
});

test('visibility applies directory modes', function () {
    $dir = sys_get_temp_dir().'/vis-dir-'.bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);
    chmod($dir, 0o750);

    try {
        $this->artisan('visibility', ['path' => $dir.'/', 'visibility' => 'private'])
            ->expectsOutputToContain('Updated 1 object')
            ->assertExitCode(0);

        expect(decoct(fileperms($dir) & 0o777))->toBe('700');

        $this->artisan('visibility', ['path' => $dir.'/', 'visibility' => 'public'])
            ->assertExitCode(0);

        expect(decoct(fileperms($dir) & 0o777))->toBe('755');
    } finally {
        remove_tree_vis($dir);
    }
});

test('visibility dry run does not change permissions', function () {
    $file = visibility_file();
    chmod($file, 0o644);

    try {
        $this->artisan('visibility', ['path' => $file, 'visibility' => 'private', '--dry-run' => true])
            ->expectsOutputToContain('No changes made')
            ->assertExitCode(0);

        expect(decoct(fileperms($file) & 0o777))->toBe('644');
    } finally {
        @unlink($file);
    }
});

test('visibility rejects an invalid value with an invalid-argument exit code', function () {
    $file = visibility_file();

    try {
        $this->artisan('visibility', ['path' => $file, 'visibility' => 'super-secret'])
            ->assertExitCode(ExitCode::INVALID);
    } finally {
        @unlink($file);
    }
});

test('visibility reports source not found for a missing local path', function () {
    $path = sys_get_temp_dir().'/storage-vis-'.bin2hex(random_bytes(4)).'.txt';

    $this->artisan('visibility', ['path' => $path, 'visibility' => 'public'])
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

function visibility_file(): string
{
    $file = sys_get_temp_dir().'/storage-vis-'.bin2hex(random_bytes(4)).'.txt';
    file_put_contents($file, 'secret');

    return $file;
}

function remove_tree_vis(string $dir): void
{
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            remove_tree_vis($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
