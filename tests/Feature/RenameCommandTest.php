<?php

declare(strict_types=1);

use App\Support\ExitCode;

test('rename moves a single file to a new name', function () {
    [$source] = rename_fixture();
    $target = sys_get_temp_dir().'/storage-rename-'.bin2hex(random_bytes(4)).'.txt';

    try {
        $this->artisan('rename', ['source' => $source.'/a.txt', 'destination' => $target])
            ->expectsOutputToContain('Renamed 1 object')
            ->assertExitCode(0);

        expect(file_get_contents($target))->toBe('alpha')
            ->and(file_exists($source.'/a.txt'))->toBeFalse();
    } finally {
        @unlink($target);
        remove_tree_rn($source);
    }
});

test('rename moves the contents of a prefix to a new prefix', function () {
    [$source, $target] = rename_fixture();

    $this->artisan('rename', ['source' => $source, 'destination' => $target])
        ->expectsOutputToContain('Renamed 2 objects')
        ->assertExitCode(0);

    expect(file_exists($target.'/a.txt'))->toBeTrue()
        ->and(file_exists($target.'/nested/b.txt'))->toBeTrue()
        ->and(file_exists($source.'/a.txt'))->toBeFalse()
        ->and(file_exists($source.'/nested/b.txt'))->toBeFalse();
});

test('rename without overwrite keeps the source when the destination exists', function () {
    [$source, $target] = rename_fixture();
    mkdir($target, 0o777, true);
    file_put_contents($target.'/a.txt', 'existing');

    $this->artisan('rename', ['source' => $source, 'destination' => $target])
        ->expectsOutputToContain('Renamed 1, skipped 1 object')
        ->assertExitCode(0);

    expect(file_get_contents($target.'/a.txt'))->toBe('existing')
        ->and(file_exists($source.'/a.txt'))->toBeTrue();
});

test('rename dry run leaves everything untouched', function () {
    [$source, $target] = rename_fixture();

    $this->artisan('rename', ['source' => $source, 'destination' => $target, '--dry-run' => true])
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);

    expect(file_exists($source.'/a.txt'))->toBeTrue()
        ->and(file_exists($source.'/nested/b.txt'))->toBeTrue();
});

test('rename fails with source-not-found exit code for a missing source', function () {
    $source = sys_get_temp_dir().'/storage-ren-'.bin2hex(random_bytes(4));
    $target = sys_get_temp_dir().'/storage-ren-dst-'.bin2hex(random_bytes(4));

    $this->artisan('rename', ['source' => $source.'/missing.txt', 'destination' => $target])
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

function rename_fixture(): array
{
    $source = sys_get_temp_dir().'/storage-rn-src-'.bin2hex(random_bytes(4));
    $target = sys_get_temp_dir().'/storage-rn-dst-'.bin2hex(random_bytes(4));
    mkdir($source.'/nested', 0o777, true);
    file_put_contents($source.'/a.txt', 'alpha');
    file_put_contents($source.'/nested/b.txt', 'beta');

    return [$source, $target];
}

function remove_tree_rn(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            remove_tree_rn($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
