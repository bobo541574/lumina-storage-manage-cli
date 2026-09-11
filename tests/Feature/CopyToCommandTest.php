<?php

declare(strict_types=1);

use App\Support\ExitCode;

test('copy-to copies a directory into the destination prefix', function () {
    [$source, $destination] = copy_to_fixture();

    $this->artisan('copy-to', ['source' => $source, 'destination' => $destination])
        ->expectsOutputToContain('Copied 2 object')
        ->assertExitCode(0);

    expect(file_exists($destination.'/a.txt'))->toBeTrue()
        ->and(file_exists($destination.'/nested/b.txt'))->toBeTrue();
});

test('copy-to copies a single file', function () {
    [$source] = copy_to_fixture();
    $target = sys_get_temp_dir().'/storage-ct-'.bin2hex(random_bytes(4)).'.txt';

    try {
        $this->artisan('copy-to', ['source' => $source.'/a.txt', 'destination' => $target])
            ->expectsOutputToContain('Copied 1 object')
            ->assertExitCode(0);

        expect(file_get_contents($target))->toBe('alpha');
    } finally {
        @unlink($target);
        rmtree_ct($source);
    }
});

test('copy-to dry run writes nothing', function () {
    [$source, $destination] = copy_to_fixture();

    $this->artisan('copy-to', ['source' => $source, 'destination' => $destination, '--dry-run' => true])
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);

    expect(file_exists($destination.'/a.txt'))->toBeFalse();
});

test('copy-to fails with source-not-found for a missing source', function () {
    [$source] = copy_to_fixture();
    $destination = sys_get_temp_dir().'/storage-ct-dst-'.bin2hex(random_bytes(4));

    $this->artisan('copy-to', ['source' => $source.'/missing.txt', 'destination' => $destination])
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

function copy_to_fixture(): array
{
    $source = sys_get_temp_dir().'/storage-ct-src-'.bin2hex(random_bytes(4));
    $destination = sys_get_temp_dir().'/storage-ct-dst-'.bin2hex(random_bytes(4));
    mkdir($source.'/nested', 0o777, true);
    file_put_contents($source.'/a.txt', 'alpha');
    file_put_contents($source.'/nested/b.txt', 'beta');

    return [$source, $destination];
}

function rmtree_ct(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            rmtree_ct($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
