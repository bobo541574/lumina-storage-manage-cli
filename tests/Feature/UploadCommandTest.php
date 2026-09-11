<?php

declare(strict_types=1);

use App\Support\ExitCode;

test('upload copies a local directory to a local destination', function () {
    [$source, $destination] = upload_fixture();

    $this->artisan('upload', ['source' => $source, 'destination' => $destination])
        ->expectsOutputToContain('Uploaded 2 objects')
        ->assertExitCode(0);

    expect(file_exists($destination.'/a.txt'))->toBeTrue()
        ->and(file_exists($destination.'/nested/b.txt'))->toBeTrue();
});

test('upload without overwrite skips existing objects', function () {
    [$source, $destination] = upload_fixture();
    mkdir($destination, 0o777, true);
    file_put_contents($destination.'/a.txt', 'existing');

    $this->artisan('upload', ['source' => $source, 'destination' => $destination])
        ->expectsOutputToContain('skipped 1')
        ->assertExitCode(0);

    expect(file_get_contents($destination.'/a.txt'))->toBe('existing');
});

test('upload dry run writes nothing', function () {
    [$source, $destination] = upload_fixture();

    $this->artisan('upload', ['source' => $source, 'destination' => $destination, '--dry-run' => true])
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);

    expect(file_exists($destination.'/a.txt'))->toBeFalse();
});

test('upload single file keeps its name inside a directory destination', function () {
    [$source] = upload_fixture();
    $destination = sys_get_temp_dir().'/storage-up-dst-'.bin2hex(random_bytes(4));

    try {
        $this->artisan('upload', ['source' => $source.'/a.txt', 'destination' => $destination.'/'])
            ->assertExitCode(0);

        expect(file_get_contents($destination.'/a.txt'))->toBe('alpha');
    } finally {
        remove_tree_up($source);
        remove_tree_up($destination);
    }
});

test('upload fails with source-not-found for a missing source', function () {
    [$source] = upload_fixture();
    $destination = sys_get_temp_dir().'/storage-up-dst-'.bin2hex(random_bytes(4));

    $this->artisan('upload', ['source' => $source.'/missing.txt', 'destination' => $destination])
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

function upload_fixture(): array
{
    $source = sys_get_temp_dir().'/storage-up-src-'.bin2hex(random_bytes(4));
    $destination = sys_get_temp_dir().'/storage-up-dst-'.bin2hex(random_bytes(4));
    mkdir($source.'/nested', 0o777, true);
    file_put_contents($source.'/a.txt', 'alpha');
    file_put_contents($source.'/nested/b.txt', 'beta');

    return [$source, $destination];
}

function remove_tree_up(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            remove_tree_up($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
