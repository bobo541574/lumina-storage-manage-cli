<?php

declare(strict_types=1);

use App\Contracts\RemoteDiscovery;
use App\Drivers\LocalStorageDriver;
use App\Drivers\RcloneStorageDriver;
use App\DTOs\StoragePath;
use App\Exceptions\ObjectNotFoundException;
use App\Services\StorageService;
use App\Support\RcloneProcess;
use App\Support\StorageLogger;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function storage_service_fixture(): StorageService
{
    $logger = new StorageLogger(sys_get_temp_dir().'/storage-svc-log-'.bin2hex(random_bytes(4)));

    return new StorageService(
        new RcloneStorageDriver(new RcloneProcess, $logger),
        new LocalStorageDriver,
    );
}

test('list returns directories and files with aggregate stats', function () {
    $base = sys_get_temp_dir().'/svc-'.bin2hex(random_bytes(4));
    mkdir($base.'/sub', 0o777, true);
    file_put_contents($base.'/a.txt', 'aaaa');
    file_put_contents($base.'/sub/b.txt', 'bbbbbb');

    try {
        $result = storage_service_fixture()->list(StoragePath::fromLocal($base), recursive: true, type: 'all');

        expect($result->count())->toBe(3)
            ->and($result->totalSize())->toBe(10);
    } finally {
        remove_tree_svc($base);
    }
});

test('list with files-only type excludes directories', function () {
    $base = sys_get_temp_dir().'/svc-'.bin2hex(random_bytes(4));
    mkdir($base.'/sub', 0o777, true);
    file_put_contents($base.'/a.txt', 'aaaa');

    try {
        $result = storage_service_fixture()->list(StoragePath::fromLocal($base), recursive: true, type: 'files');

        expect($result->count())->toBe(1)
            ->and($result->entries[0]->isFile)->toBeTrue();
    } finally {
        remove_tree_svc($base);
    }
});

test('list throws for a missing path', function () {
    $base = sys_get_temp_dir().'/svc-missing-'.bin2hex(random_bytes(4));

    try {
        storage_service_fixture()->list(StoragePath::fromLocal($base), recursive: false, type: 'all');
        $this->fail('Expected an exception.');
    } catch (ObjectNotFoundException) {
        expect(true)->toBeTrue();
    }
});

test('exists resolves local paths', function () {
    $base = sys_get_temp_dir().'/svc-'.bin2hex(random_bytes(4));
    mkdir($base, 0o777, true);
    file_put_contents($base.'/here.txt', 'x');

    try {
        $service = storage_service_fixture();

        expect($service->exists(StoragePath::fromLocal($base.'/here.txt')))->toBeTrue()
            ->and($service->exists(StoragePath::fromLocal($base.'/gone.txt')))->toBeFalse();
    } finally {
        remove_tree_svc($base);
    }
});

function remove_tree_svc(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            remove_tree_svc($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

test('remotes and buckets are cached for the configured ttl', function () {
    $driver = new class implements RemoteDiscovery
    {
        public int $calls = 0;

        public function remotes(): array
        {
            $this->calls++;

            return ['alpha'];
        }

        public function buckets(string $remote): array
        {
            $this->calls++;

            return ['bucket-a'];
        }
    };

    $cache = new Repository(new ArrayStore);
    $service = new StorageService($driver, new LocalStorageDriver, $cache, 60);

    expect($service->remotes())->toBe(['alpha'])
        ->and($service->remotes())->toBe(['alpha'])
        ->and($service->buckets('alpha'))->toBe(['bucket-a'])
        ->and($service->buckets('alpha'))->toBe(['bucket-a'])
        ->and($driver->calls)->toBe(2);
});

test('remotes are not cached when the ttl is zero', function () {
    $driver = new class implements RemoteDiscovery
    {
        public int $calls = 0;

        public function remotes(): array
        {
            $this->calls++;

            return [];
        }

        public function buckets(string $remote): array
        {
            return [];
        }
    };

    $service = new StorageService($driver, new LocalStorageDriver, new Repository(new ArrayStore), 0);

    $service->remotes();
    $service->remotes();

    expect($driver->calls)->toBe(2);
});
