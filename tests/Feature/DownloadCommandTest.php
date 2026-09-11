<?php

declare(strict_types=1);

use App\Support\ExitCode;

test('download copies a local directory to a local destination', function () {
    [$source, $destination] = download_fixture();

    $this->artisan('download', ['source' => $source, 'destination' => $destination])
        ->expectsOutputToContain('Downloaded 2 object')
        ->assertExitCode(0);

    expect(file_get_contents($destination.'/a.txt'))->toBe('alpha')
        ->and(file_exists($destination.'/nested/b.txt'))->toBeTrue();
});

test('download single object to a file path', function () {
    [$source] = download_fixture();
    $target = sys_get_temp_dir().'/storage-dl-'.bin2hex(random_bytes(4)).'.txt';

    try {
        $this->artisan('download', ['source' => $source.'/a.txt', 'destination' => $target])
            ->expectsOutputToContain('Downloaded 1 object')
            ->assertExitCode(0);

        expect(file_get_contents($target))->toBe('alpha');
    } finally {
        @unlink($target);
        remove_tree_dl($source);
    }
});

test('download dry run writes nothing', function () {
    [$source, $destination] = download_fixture();

    $this->artisan('download', ['source' => $source, 'destination' => $destination, '--dry-run' => true])
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);

    expect(file_exists($destination.'/a.txt'))->toBeFalse();
});

test('download fails with source-not-found for a missing source', function () {
    [$source] = download_fixture();
    $destination = sys_get_temp_dir().'/storage-dl-dst-'.bin2hex(random_bytes(4));

    $this->artisan('download', ['source' => $source.'/missing.txt', 'destination' => $destination])
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

function download_fixture(): array
{
    $source = sys_get_temp_dir().'/storage-dl-src-'.bin2hex(random_bytes(4));
    $destination = sys_get_temp_dir().'/storage-dl-dst-'.bin2hex(random_bytes(4));
    mkdir($source.'/nested', 0o777, true);
    file_put_contents($source.'/a.txt', 'alpha');
    file_put_contents($source.'/nested/b.txt', 'beta');

    return [$source, $destination];
}

function remove_tree_dl(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            remove_tree_dl($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
