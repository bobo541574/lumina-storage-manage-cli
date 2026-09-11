<?php

declare(strict_types=1);

use App\Support\ExitCode;

test('duplicate copies a single file without deleting the source', function () {
    [$source] = duplicate_fixture();
    $target = sys_get_temp_dir().'/storage-dup-'.bin2hex(random_bytes(4)).'.txt';

    try {
        $this->artisan('duplicate', ['source' => $source.'/a.txt', 'destination' => $target])
            ->expectsOutputToContain('Duplicated 1 object')
            ->assertExitCode(0);

        expect(file_get_contents($target))->toBe('alpha')
            ->and(file_exists($source.'/a.txt'))->toBeTrue();
    } finally {
        @unlink($target);
        remove_tree_dp($source);
    }
});

test('duplicate copies the contents of a prefix and preserves the source', function () {
    [$source, $target] = duplicate_fixture();

    $this->artisan('duplicate', ['source' => $source, 'destination' => $target])
        ->expectsOutputToContain('Duplicated 2 object')
        ->assertExitCode(0);

    expect(file_exists($target.'/a.txt'))->toBeTrue()
        ->and(file_exists($target.'/nested/b.txt'))->toBeTrue()
        ->and(file_exists($source.'/a.txt'))->toBeTrue()
        ->and(file_exists($source.'/nested/b.txt'))->toBeTrue();
});

test('duplicate without overwrite skips existing objects', function () {
    [$source, $target] = duplicate_fixture();
    mkdir($target, 0o777, true);
    file_put_contents($target.'/a.txt', 'existing');

    $this->artisan('duplicate', ['source' => $source, 'destination' => $target])
        ->expectsOutputToContain('Duplicated 1, skipped 1 object')
        ->assertExitCode(0);

    expect(file_get_contents($target.'/a.txt'))->toBe('existing');
});

test('duplicate dry run writes nothing', function () {
    [$source, $target] = duplicate_fixture();

    $this->artisan('duplicate', ['source' => $source, 'destination' => $target, '--dry-run' => true])
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);

    expect(file_exists($target.'/a.txt'))->toBeFalse();
});

test('duplicate fails with source-not-found exit code for a missing source', function () {
    $source = sys_get_temp_dir().'/storage-dup-'.bin2hex(random_bytes(4));
    $target = sys_get_temp_dir().'/storage-dup-dst-'.bin2hex(random_bytes(4));

    $this->artisan('duplicate', ['source' => $source.'/missing.txt', 'destination' => $target])
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

function duplicate_fixture(): array
{
    $source = sys_get_temp_dir().'/storage-dp-src-'.bin2hex(random_bytes(4));
    $target = sys_get_temp_dir().'/storage-dp-dst-'.bin2hex(random_bytes(4));
    mkdir($source.'/nested', 0o777, true);
    file_put_contents($source.'/a.txt', 'alpha');
    file_put_contents($source.'/nested/b.txt', 'beta');

    return [$source, $target];
}

function remove_tree_dp(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            remove_tree_dp($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
