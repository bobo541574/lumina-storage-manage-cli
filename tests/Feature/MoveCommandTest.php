<?php

declare(strict_types=1);

use App\Support\ExitCode;

test('move transfers directory contents and deletes the source objects', function () {
    [$source, $destination] = move_fixture();

    $this->artisan('move', ['source' => $source, 'destination' => $destination])
        ->expectsOutputToContain('Moved 2 object')
        ->assertExitCode(0);

    expect(file_exists($destination.'/a.txt'))->toBeTrue()
        ->and(file_exists($destination.'/nested/b.txt'))->toBeTrue()
        ->and(file_exists($source.'/a.txt'))->toBeFalse()
        ->and(file_exists($source.'/nested/b.txt'))->toBeFalse();
});

test('move without overwrite keeps the source object when the destination exists', function () {
    [$source, $destination] = move_fixture();
    mkdir($destination, 0o777, true);
    file_put_contents($destination.'/a.txt', 'existing');

    $this->artisan('move', ['source' => $source, 'destination' => $destination])
        ->expectsOutputToContain('Moved 1, skipped 1 object')
        ->assertExitCode(0);

    expect(file_get_contents($destination.'/a.txt'))->toBe('existing')
        ->and(file_exists($source.'/a.txt'))->toBeTrue()
        ->and(file_exists($source.'/nested/b.txt'))->toBeFalse();
});

test('move single file to a single file destination', function () {
    [$source] = move_fixture();
    $target = sys_get_temp_dir().'/storage-move-'.bin2hex(random_bytes(4)).'.txt';

    try {
        $this->artisan('move', ['source' => $source.'/a.txt', 'destination' => $target])
            ->expectsOutputToContain('Moved 1 object')
            ->assertExitCode(0);

        expect(file_get_contents($target))->toBe('alpha')
            ->and(file_exists($source.'/a.txt'))->toBeFalse();
    } finally {
        @unlink($target);
        remove_tree_mv($source);
    }
});

test('move dry run leaves the source untouched', function () {
    [$source, $destination] = move_fixture();

    $this->artisan('move', ['source' => $source, 'destination' => $destination, '--dry-run' => true])
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);

    expect(file_exists($source.'/a.txt'))->toBeTrue()
        ->and(file_exists($destination.'/a.txt'))->toBeFalse();
});

test('move reports nothing for an empty source prefix', function () {
    [$source, $destination] = move_fixture();
    remove_tree_mv($source);
    mkdir($source, 0o777, true);

    $this->artisan('move', ['source' => $source, 'destination' => $destination])
        ->expectsOutputToContain('Nothing to move')
        ->assertExitCode(0);
});

test('move fails with source-not-found exit code for a missing source', function () {
    $source = sys_get_temp_dir().'/storage-mv-src-'.bin2hex(random_bytes(4));
    $destination = sys_get_temp_dir().'/storage-mv-dst-'.bin2hex(random_bytes(4));

    $this->artisan('move', ['source' => $source.'/missing.txt', 'destination' => $destination])
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

test('move reports a partial operation when source deletion fails', function () {
    [$source, $destination] = move_fixture();
    chmod($source, 0o555);

    try {
        $this->artisan('move', ['source' => $source, 'destination' => $destination])
            ->expectsOutputToContain('failed 1')
            ->assertExitCode(ExitCode::PARTIAL);

        expect(file_exists($destination.'/a.txt'))->toBeTrue()
            ->and(file_exists($source.'/a.txt'))->toBeTrue();
    } finally {
        chmod($source, 0o755);
    }
});

function move_fixture(): array
{
    $source = sys_get_temp_dir().'/storage-mv-src-'.bin2hex(random_bytes(4));
    $destination = sys_get_temp_dir().'/storage-mv-dst-'.bin2hex(random_bytes(4));
    mkdir($source.'/nested', 0o777, true);
    file_put_contents($source.'/a.txt', 'alpha');
    file_put_contents($source.'/nested/b.txt', 'beta');

    return [$source, $destination];
}

function remove_tree_mv(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            remove_tree_mv($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
