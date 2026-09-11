<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function remote_config_file(): string
{
    return sys_get_temp_dir().'/rmts-cfg-'.bin2hex(random_bytes(4));
}

function remote_config_set(string $file): void
{
    putenv('RCLONE_CONFIG='.$file);
}

function remote_config_clear(): void
{
    putenv('RCLONE_CONFIG');
}

test('remotes:add configures a remote through the rclone config', function () {
    $file = remote_config_file();
    remote_config_set($file);

    try {
        Artisan::call('remotes:add', ['name' => 'rbx_test', 'type' => 'local']);

        expect(Artisan::output())->toContain('Configured remote rbx_test (type: local).');
        expect(file_get_contents($file))->toContain('[rbx_test]');

        Artisan::call('remotes');

        expect(Artisan::output())->toContain('rbx_test:');
    } finally {
        remote_config_clear();
        @unlink($file);
    }
});

test('remotes:add rejects an invalid name and a malformed key', function () {
    $file = remote_config_file();
    remote_config_set($file);

    try {
        Artisan::call('remotes:add', ['name' => 'bad name!', 'type' => 'local']);
        expect(Artisan::output())->toContain('Invalid remote name');

        Artisan::call('remotes:add', ['name' => 'rbx_ok', 'type' => 'local', 'config' => ['nope']]);
        expect(Artisan::output())->toContain('Expected KEY=VALUE');
    } finally {
        remote_config_clear();
        @unlink($file);
    }
});

test('remotes:show prints one remote with secrets redacted', function () {
    $file = remote_config_file();
    remote_config_set($file);

    try {
        Artisan::call('remotes:add', ['name' => 'rbx_show', 'type' => 'local', 'config' => ['token=TOP-SECRET-123', 'endpoint=https://x.example']]);

        Artisan::call('remotes:show', ['name' => 'rbx_show']);

        $out = Artisan::output();

        expect($out)->toContain('type = local')
            ->and($out)->toContain('endpoint = https://x.example')
            ->and($out)->toContain('token = ***')
            ->and($out)->not->toContain('TOP-SECRET-123');

        Artisan::call('remotes:show', ['name' => 'rbx_missing']);
        expect(Artisan::output())->toContain('Remote "rbx_missing" is not configured.');
    } finally {
        remote_config_clear();
        @unlink($file);
    }
});

test('remotes:forget deletes a remote only with confirmation or --force', function () {
    $file = remote_config_file();
    remote_config_set($file);

    try {
        $this->artisan('remotes:forget', ['name' => 'rbx_missing', '--force' => true])
            ->expectsOutputToContain('Remote "rbx_missing" is not configured.')
            ->assertExitCode(3);

        Artisan::call('remotes:add', ['name' => 'rbx_del', 'type' => 'local']);

        $this->artisan('remotes:forget', ['name' => 'rbx_del'])
            ->expectsConfirmation('Delete remote "rbx_del" and its credentials?', 'no')
            ->expectsOutputToContain('Cancelled.')
            ->assertExitCode(0);

        expect(file_get_contents($file))->toContain('[rbx_del]');

        Artisan::call('remotes:forget', ['name' => 'rbx_del', '--force' => true]);

        expect(Artisan::output())->toContain('Remote rbx_del deleted.')
            ->and(file_get_contents($file))->not->toContain('[rbx_del]');

        Artisan::call('remotes');

        expect(Artisan::output())->not->toContain('rbx_del:');
    } finally {
        remote_config_clear();
        @unlink($file);
    }
});
