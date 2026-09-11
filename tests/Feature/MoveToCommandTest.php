<?php

declare(strict_types=1);

use App\Support\ExitCode;

test('move-to moves a single file (copy, verify, delete source)', function () {
    [$source] = move_to_fixture();
    $target = sys_get_temp_dir().'/storage-mt-'.bin2hex(random_bytes(4)).'.txt';

    try {
        $this->artisan('move-to', ['source' => $source.'/a.txt', 'destination' => $target])
            ->expectsOutputToContain('Moved 1 object')
            ->assertExitCode(0);

        expect(file_get_contents($target))->toBe('alpha')
            ->and(file_exists($source.'/a.txt'))->toBeFalse();
    } finally {
        @unlink($target);
        rmtree_mt($source);
    }
});

test('move-to moves the contents of a prefix', function () {
    [$source, $destination] = move_to_fixture();

    $this->artisan('move-to', ['source' => $source, 'destination' => $destination])
        ->expectsOutputToContain('Moved 2 objects')
        ->assertExitCode(0);

    expect(file_exists($destination.'/a.txt'))->toBeTrue()
        ->and(file_exists($destination.'/nested/b.txt'))->toBeTrue()
        ->and(file_exists($source.'/a.txt'))->toBeFalse();
});

test('move-to dry run leaves the source untouched', function () {
    [$source, $destination] = move_to_fixture();

    $this->artisan('move-to', ['source' => $source, 'destination' => $destination, '--dry-run' => true])
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);

    expect(file_exists($source.'/a.txt'))->toBeTrue();
});

test('move-to fails with source-not-found for a missing source', function () {
    [$source] = move_to_fixture();
    $destination = sys_get_temp_dir().'/storage-mt-dst-'.bin2hex(random_bytes(4));

    $this->artisan('move-to', ['source' => $source.'/missing.txt', 'destination' => $destination])
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

function move_to_fixture(): array
{
    $source = sys_get_temp_dir().'/storage-mt-src-'.bin2hex(random_bytes(4));
    $destination = sys_get_temp_dir().'/storage-mt-dst-'.bin2hex(random_bytes(4));
    mkdir($source.'/nested', 0o777, true);
    file_put_contents($source.'/a.txt', 'alpha');
    file_put_contents($source.'/nested/b.txt', 'beta');

    return [$source, $destination];
}

function rmtree_mt(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            rmtree_mt($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
