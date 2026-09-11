<?php

declare(strict_types=1);

use App\Support\ExitCode;

test('copy moves directory contents into the destination preserving structure', function () {
    [$source, $destination] = remotes_with_fixture();

    $this->artisan('copy', ['source' => $source, 'destination' => $destination])
        ->expectsOutputToContain('Copied 2 object')
        ->assertExitCode(0);

    expect(file_exists($destination.'/a.txt'))->toBeTrue()
        ->and(file_exists($destination.'/nested/b.txt'))->toBeTrue()
        ->and(file_exists($source.'/a.txt'))->toBeTrue();
});

test('copy without overwrite skips existing objects', function () {
    [$source, $destination] = remotes_with_fixture();
    mkdir($destination, 0o777, true);
    file_put_contents($destination.'/a.txt', 'already-there');

    $this->artisan('copy', ['source' => $source, 'destination' => $destination])
        ->expectsOutputToContain('skipped 1')
        ->assertExitCode(0);

    expect(file_get_contents($destination.'/a.txt'))->toBe('already-there');
});

test('copy with overwrite replaces existing objects', function () {
    [$source, $destination] = remotes_with_fixture();
    mkdir($destination, 0o777, true);
    file_put_contents($destination.'/a.txt', 'stale');

    $this->artisan('copy', ['source' => $source, 'destination' => $destination, '--overwrite' => true])
        ->assertExitCode(0);

    expect(file_get_contents($destination.'/a.txt'))->toBe('alpha');
});

test('copy dry run writes nothing to the destination', function () {
    [$source, $destination] = remotes_with_fixture();

    $this->artisan('copy', ['source' => $source, 'destination' => $destination, '--dry-run' => true])
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);

    expect(file_exists($destination.'/a.txt'))->toBeFalse();
});

test('copy a single file to a single file', function () {
    [$source] = remotes_with_fixture();
    $target = sys_get_temp_dir().'/storage-copy-'.bin2hex(random_bytes(4)).'.txt';

    try {
        $this->artisan('copy', ['source' => $source.'/a.txt', 'destination' => $target])
            ->expectsOutputToContain('Copied 1 object')
            ->assertExitCode(0);

        expect(file_get_contents($target))->toBe('alpha');
    } finally {
        @unlink($target);
    }
});

test('copy a single file into a directory keeps its name', function () {
    [$source] = remotes_with_fixture();
    $target = sys_get_temp_dir().'/storage-copy-'.bin2hex(random_bytes(4));

    try {
        $this->artisan('copy', ['source' => $source.'/a.txt', 'destination' => $target.'/'])
            ->assertExitCode(0);

        expect(file_get_contents($target.'/a.txt'))->toBe('alpha');
    } finally {
        remove_tree_cw($target);
    }
});

test('copy fails with source-not-found exit code for a missing source', function () {
    [$source] = remotes_with_fixture();
    $target = sys_get_temp_dir().'/storage-copy-'.bin2hex(random_bytes(4));

    $this->artisan('copy', ['source' => $source.'/missing.txt', 'destination' => $target])
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

test('copy reports a failure when the destination parent is not a directory', function () {
    [$source] = remotes_with_fixture();
    $base = sys_get_temp_dir().'/copy-block-'.bin2hex(random_bytes(4));
    mkdir($base, 0o777, true);
    file_put_contents($base.'/x', 'a file, not a directory');

    try {
        $this->artisan('copy', ['source' => $source.'/a.txt', 'destination' => $base.'/x/a.txt'])
            ->expectsOutputToContain('failed 1')
            ->assertExitCode(ExitCode::FAILURE);
    } finally {
        @unlink($base.'/x');
        @rmdir($base);
    }
});

function remotes_with_fixture(): array
{
    $source = sys_get_temp_dir().'/storage-src-'.bin2hex(random_bytes(4));
    $destination = sys_get_temp_dir().'/storage-dst-'.bin2hex(random_bytes(4));
    mkdir($source.'/nested', 0o777, true);
    file_put_contents($source.'/a.txt', 'alpha');
    file_put_contents($source.'/nested/b.txt', 'beta');

    return [$source, $destination];
}

function remove_tree_cw(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            remove_tree_cw($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
