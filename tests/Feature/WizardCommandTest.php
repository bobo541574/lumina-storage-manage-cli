<?php

declare(strict_types=1);

test('wizard lists the selected local directory', function () {
    [$dir] = wizard_fixture();

    $this->artisan('wizard')
        ->expectsQuestion('Select storage', 'local')
        ->expectsQuestion('Local working directory', $dir)
        ->expectsQuestion('Select operation', 'List')
        ->expectsQuestion('Source path', $dir)
        ->expectsOutputToContain('1 file')
        ->assertExitCode(0);
});

test('wizard copies between local directories', function () {
    [$src, $dst] = wizard_fixture();

    $this->artisan('wizard')
        ->expectsQuestion('Select storage', 'local')
        ->expectsQuestion('Local working directory', $src)
        ->expectsQuestion('Select operation', 'Copy')
        ->expectsQuestion('Source path', $src)
        ->expectsQuestion('Destination path', $dst)
        ->expectsConfirmation('Overwrite existing objects?', 'no')
        ->expectsConfirmation('Dry run (no changes)?', 'no')
        ->expectsConfirmation('Show live progress?', 'no')
        ->expectsConfirmation('Run operation?', 'yes')
        ->expectsOutputToContain('Copied 2 object')
        ->assertExitCode(0);

    expect(file_exists($dst.'/a.txt'))->toBeTrue()
        ->and(file_exists($dst.'/nested/b.txt'))->toBeTrue()
        ->and(file_exists($src.'/a.txt'))->toBeTrue();
});

test('wizard aborts a transfer when the final confirmation is declined', function () {
    [$src, $dst] = wizard_fixture();

    $this->artisan('wizard')
        ->expectsQuestion('Select storage', 'local')
        ->expectsQuestion('Local working directory', $src)
        ->expectsQuestion('Select operation', 'Copy')
        ->expectsQuestion('Source path', $src)
        ->expectsQuestion('Destination path', $dst)
        ->expectsConfirmation('Overwrite existing objects?', 'no')
        ->expectsConfirmation('Dry run (no changes)?', 'no')
        ->expectsConfirmation('Show live progress?', 'no')
        ->expectsConfirmation('Run operation?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(0);

    expect(file_exists($dst.'/a.txt'))->toBeFalse();
});

test('wizard dry run transfers without modifying anything', function () {
    [$src, $dst] = wizard_fixture();

    $this->artisan('wizard')
        ->expectsQuestion('Select storage', 'local')
        ->expectsQuestion('Local working directory', $src)
        ->expectsQuestion('Select operation', 'Copy')
        ->expectsQuestion('Source path', $src)
        ->expectsQuestion('Destination path', $dst)
        ->expectsConfirmation('Overwrite existing objects?', 'no')
        ->expectsConfirmation('Dry run (no changes)?', 'yes')
        ->expectsConfirmation('Show live progress?', 'no')
        ->expectsOutputToContain('No changes made.')
        ->assertExitCode(0);

    expect(file_exists($dst.'/a.txt'))->toBeFalse();
});

test('wizard delete defers to the delete confirmation', function () {
    [$src] = wizard_fixture();
    $path = $src.'/a.txt';

    $this->artisan('wizard')
        ->expectsQuestion('Select storage', 'local')
        ->expectsQuestion('Local working directory', $src)
        ->expectsQuestion('Select operation', 'Delete')
        ->expectsQuestion('Path to delete', $path)
        ->expectsConfirmation('Delete 1 object(s). Continue?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();
});

function wizard_fixture(): array
{
    $base = sys_get_temp_dir().'/wz-'.bin2hex(random_bytes(4));
    WizardCommandTest::$bases[] = $base;
    $src = $base.'/src';
    $dst = $base.'/dst';
    mkdir($src.'/nested', 0o777, true);
    file_put_contents($src.'/a.txt', 'alpha');
    file_put_contents($src.'/nested/b.txt', 'beta');

    return [$src, $dst];
}

afterEach(function () {
    while ($base = array_pop(WizardCommandTest::$bases)) {
        if (str_starts_with($base, sys_get_temp_dir()) && is_dir($base)) {
            remove_tree_wz($base);
        }
    }
});

class WizardCommandTest
{
    public static array $bases = [];
}

function remove_tree_wz(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            remove_tree_wz($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
