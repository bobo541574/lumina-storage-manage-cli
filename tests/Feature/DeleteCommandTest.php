<?php

declare(strict_types=1);

use App\Support\ExitCode;
use Illuminate\Support\Facades\Artisan;

test('delete removes a single local file with force', function () {
    [$dir] = delete_fixture();

    $this->artisan('delete', ['path' => $dir.'/a.txt', '--force' => true])
        ->expectsOutputToContain('Deleted 1 object')
        ->assertExitCode(0);

    expect(file_exists($dir.'/a.txt'))->toBeFalse()
        ->and(file_exists($dir.'/nested/b.txt'))->toBeTrue();
});

test('delete removes all objects under a prefix with force', function () {
    [$dir] = delete_fixture();

    $this->artisan('delete', ['path' => $dir.'/', '--force' => true])
        ->expectsOutputToContain('Deleted 2 object')
        ->assertExitCode(0);

    expect(file_exists($dir.'/a.txt'))->toBeFalse()
        ->and(file_exists($dir.'/nested/b.txt'))->toBeFalse();
});

test('delete dry run reports the scope without removing anything', function () {
    [$dir] = delete_fixture();

    $this->artisan('delete', ['path' => $dir.'/', '--dry-run' => true])
        ->expectsOutputToContain('Would delete 2 objects')
        ->assertExitCode(0);

    expect(file_exists($dir.'/a.txt'))->toBeTrue()
        ->and(file_exists($dir.'/nested/b.txt'))->toBeTrue();
});

test('delete declines the confirmation by default and removes nothing', function () {
    [$dir] = delete_fixture();

    $exit = Artisan::call('delete', ['path' => $dir.'/a.txt']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Aborted.')
        ->and(file_exists($dir.'/a.txt'))->toBeTrue();
});

test('delete honours an explicit confirmation', function () {
    [$dir] = delete_fixture();

    $this->artisan('delete', ['path' => $dir.'/a.txt'])
        ->expectsConfirmation('Delete 1 object(s). Continue?', 'yes')
        ->assertExitCode(0);

    expect(file_exists($dir.'/a.txt'))->toBeFalse();
});

test('delete reports a missing path as not found instead of succeeding', function () {
    $path = sys_get_temp_dir().'/storage-del-'.bin2hex(random_bytes(4)).'.txt';

    $this->artisan('delete', ['path' => $path])
        ->expectsOutputToContain('Nothing found at: '.$path)
        ->assertExitCode(ExitCode::SOURCE_NOT_FOUND);
});

test('delete reports nothing to do for a path that exists but is empty', function () {
    $dir = sys_get_temp_dir().'/storage-del-empty-'.bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);

    try {
        $this->artisan('delete', ['path' => $dir.'/', '--force' => true])
            ->expectsOutputToContain('Nothing to delete.')
            ->assertExitCode(0);
    } finally {
        @rmdir($dir);
    }
});

test('delete rejects an unparseable path with an invalid argument exit code', function () {
    $this->artisan('delete', ['path' => 'remote:'])
        ->assertExitCode(ExitCode::INVALID);
});

function delete_fixture(): array
{
    $dir = sys_get_temp_dir().'/storage-del-'.bin2hex(random_bytes(4));
    mkdir($dir.'/nested', 0o777, true);
    file_put_contents($dir.'/a.txt', 'alpha');
    file_put_contents($dir.'/nested/b.txt', 'beta');

    return [$dir];
}
