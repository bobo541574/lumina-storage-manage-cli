<?php

declare(strict_types=1);

use App\Drivers\LocalStorageDriver;
use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\DTOs\TransferStatus;
use App\Exceptions\ObjectNotFoundException;
use App\Support\StorageLogger;

function local_driver(): LocalStorageDriver
{
    return new LocalStorageDriver;
}

function local_path(string $path): StoragePath
{
    return StoragePath::fromLocal($path);
}

function local_fixture_base(): string
{
    return sys_get_temp_dir().'/lsd-'.bin2hex(random_bytes(4));
}

test('lists a directory recursively with relative paths', function () {
    $base = local_fixture_base();
    mkdir($base.'/top/nested', 0o777, true);
    file_put_contents($base.'/top/a.txt', 'a');
    file_put_contents($base.'/top/nested/b.txt', 'b');

    try {
        $result = local_driver()->list(local_path($base.'/top'), recursive: true, directories: true, files: true);
        $paths = array_map(static fn ($entry) => $entry->path, $result->entries);

        expect($paths)->toContain('a.txt', 'nested', 'nested/b.txt')
            ->and($result->count())->toBe(3);
    } finally {
        remove_tree_lsd($base);
    }
});

test('lists files only when directories are excluded', function () {
    $base = local_fixture_base();
    mkdir($base.'/dir', 0o777, true);
    file_put_contents($base.'/file.txt', 'x');

    try {
        $result = local_driver()->list(local_path($base), recursive: false, directories: false, files: true);
        expect(array_map(static fn ($entry) => $entry->path, $result->entries))
            ->toBe(['file.txt']);
    } finally {
        remove_tree_lsd($base);
    }
});

test('lists a single file path as one entry', function () {
    $base = local_fixture_base();
    mkdir($base, 0o777, true);
    file_put_contents($base.'/only.txt', 'x');

    try {
        $result = local_driver()->list(local_path($base.'/only.txt'), recursive: false, directories: true, files: true);
        expect($result->count())->toBe(1)
            ->and($result->entries[0]->isFile)->toBeTrue();
    } finally {
        remove_tree_lsd($base);
    }
});

test('lists each directory recursive total size when requested', function () {
    $base = local_fixture_base();
    mkdir($base.'/sub/nested', 0o777, true);
    file_put_contents($base.'/top.txt', 'top');
    file_put_contents($base.'/sub/a.txt', 'a');
    file_put_contents($base.'/sub/nested/b.txt', str_repeat('x', 100));

    try {
        $sizes = [];

        foreach (local_driver()->list(local_path($base), recursive: true, directories: true, files: true, withDirectorySizes: true)->entries as $entry) {
            if ($entry->isDirectory) {
                $sizes[$entry->path] = $entry->size;
            }
        }

        expect($sizes)->toMatchArray(['sub' => 101, 'sub/nested' => 100]);
    } finally {
        remove_tree_lsd($base);
    }
});

test('a directory size is zero when no size was requested', function () {
    $base = local_fixture_base();
    mkdir($base.'/sub', 0o777, true);
    file_put_contents($base.'/sub/a.txt', str_repeat('x', 100));

    try {
        $result = local_driver()->list(local_path($base), recursive: false, directories: true, files: true);
        $dir = null;

        foreach ($result->entries as $entry) {
            if ($entry->isDirectory) {
                $dir = $entry;
            }
        }

        expect($dir->size)->toBe(0);
    } finally {
        remove_tree_lsd($base);
    }
});

test('lists a missing path with source-not-found semantics', function () {
    $base = local_fixture_base();

    try {
        local_driver()->list(local_path($base.'/missing'), recursive: false, directories: true, files: true);
        $this->fail('Expected an exception.');
    } catch (ObjectNotFoundException) {
        expect(true)->toBeTrue();
    } finally {
        if (is_dir($base)) {
            rmdir($base);
        }
    }
});

test('exists reflects the filesystem', function () {
    $base = local_fixture_base();
    mkdir($base, 0o777, true);
    file_put_contents($base.'/f.txt', 'x');

    try {
        expect(local_driver()->exists(local_path($base.'/f.txt')))->toBeTrue()
            ->and(local_driver()->exists(local_path($base.'/nope.txt')))->toBeFalse();
    } finally {
        remove_tree_lsd($base);
    }
});

test('copy preserves directory structure and reports counts', function () {
    $src = local_fixture_base();
    $dst = local_fixture_base();
    mkdir($src.'/nested', 0o777, true);
    file_put_contents($src.'/a.txt', 'alpha');
    file_put_contents($src.'/nested/b.txt', 'beta');

    try {
        $result = local_driver()->copy(local_path($src), local_path($dst));
        expect($result->status)->toBe(TransferStatus::Success)
            ->and($result->copied)->toBe(2);

        expect(file_get_contents($dst.'/a.txt'))->toBe('alpha')
            ->and(file_get_contents($dst.'/nested/b.txt'))->toBe('beta');
    } finally {
        remove_tree_lsd($src);
        remove_tree_lsd($dst);
    }
});

test('copy skips existing objects without overwrite', function () {
    $src = local_fixture_base();
    $dst = local_fixture_base();
    mkdir($src, 0o777, true);
    mkdir($dst, 0o777, true);
    file_put_contents($src.'/a.txt', 'new');
    file_put_contents($dst.'/a.txt', 'old');

    try {
        $result = local_driver()->copy(local_path($src), local_path($dst));
        expect($result->copied)->toBe(0)
            ->and($result->skipped)->toBe(1)
            ->and(file_get_contents($dst.'/a.txt'))->toBe('old');
    } finally {
        remove_tree_lsd($src);
        remove_tree_lsd($dst);
    }
});

test('copy overwrites existing objects when requested', function () {
    $src = local_fixture_base();
    $dst = local_fixture_base();
    mkdir($src, 0o777, true);
    mkdir($dst, 0o777, true);
    file_put_contents($src.'/a.txt', 'new');
    file_put_contents($dst.'/a.txt', 'old');

    try {
        $result = local_driver()->copy(local_path($src), local_path($dst), new TransferOptions(overwrite: true));
        expect($result->copied)->toBe(1)
            ->and(file_get_contents($dst.'/a.txt'))->toBe('new');
    } finally {
        remove_tree_lsd($src);
        remove_tree_lsd($dst);
    }
});

test('copy dry run counts files without writing', function () {
    $src = local_fixture_base();
    $dst = local_fixture_base();
    mkdir($src, 0o777, true);
    file_put_contents($src.'/a.txt', 'a');

    try {
        $result = local_driver()->copy(local_path($src), local_path($dst), new TransferOptions(dryRun: true));
        expect($result->status)->toBe(TransferStatus::Success)
            ->and($result->copied)->toBe(1)
            ->and(is_dir($dst))->toBeFalse();
    } finally {
        remove_tree_lsd($src);
    }
});

test('copy reports a failed result for a missing source', function () {
    $base = local_fixture_base();
    $dst = local_fixture_base();

    try {
        $result = local_driver()->copy(local_path($base.'/missing'), local_path($dst));
        expect($result->status)->toBe(TransferStatus::Failed)
            ->and($result->failed)->toBe(1);
    } finally {
        if (is_dir($base)) {
            rmdir($base);
        }
        if (is_dir($dst)) {
            rmdir($dst);
        }
    }
});

test('delete removes a file and returns the deleted count', function () {
    $base = local_fixture_base();
    mkdir($base, 0o777, true);
    file_put_contents($base.'/a.txt', 'x');

    try {
        $result = local_driver()->delete(local_path($base.'/a.txt'));
        expect($result->status)->toBe(TransferStatus::Success)
            ->and($result->copied)->toBe(1)
            ->and(file_exists($base.'/a.txt'))->toBeFalse();
    } finally {
        remove_tree_lsd($base);
    }
});

test('delete of a missing path succeeds with zero count', function () {
    $base = local_fixture_base();
    mkdir($base, 0o777, true);

    try {
        $result = local_driver()->delete(local_path($base.'/nope.txt'));
        expect($result->status)->toBe(TransferStatus::Success)
            ->and($result->copied)->toBe(0);
    } finally {
        remove_tree_lsd($base);
    }
});

test('delete removes a nested directory tree', function () {
    $base = local_fixture_base();
    mkdir($base.'/nested/deep', 0o777, true);
    file_put_contents($base.'/nested/a.txt', 'a');
    file_put_contents($base.'/nested/deep/b.txt', 'b');

    try {
        $result = local_driver()->delete(local_path($base.'/nested'));
        expect($result->copied)->toBe(2)
            ->and(is_dir($base.'/nested'))->toBeFalse();
    } finally {
        remove_tree_lsd($base);
    }
});

test('delete dry run counts the files but removes nothing', function () {
    $base = local_fixture_base();
    mkdir($base.'/nested/deep', 0o777, true);
    file_put_contents($base.'/nested/a.txt', 'a');
    file_put_contents($base.'/nested/deep/b.txt', 'b');

    try {
        $result = local_driver()->delete(local_path($base.'/nested'), new TransferOptions(dryRun: true));
        expect($result->status)->toBe(TransferStatus::Success)
            ->and($result->copied)->toBe(2)
            ->and(is_dir($base.'/nested'))->toBeTrue()
            ->and(file_exists($base.'/nested/a.txt'))->toBeTrue()
            ->and(file_exists($base.'/nested/deep/b.txt'))->toBeTrue();
    } finally {
        remove_tree_lsd($base);
    }
});

test('move deletes the source on success', function () {
    $src = local_fixture_base();
    $dst = local_fixture_base();
    mkdir($src, 0o777, true);
    file_put_contents($src.'/a.txt', 'moved');

    try {
        $result = local_driver()->move(local_path($src.'/a.txt'), local_path($dst.'/a.txt'));
        expect($result->status)->toBe(TransferStatus::Success)
            ->and($result->copied)->toBe(1)
            ->and(file_exists($src.'/a.txt'))->toBeFalse()
            ->and(file_get_contents($dst.'/a.txt'))->toBe('moved');
    } finally {
        remove_tree_lsd($src);
        remove_tree_lsd($dst);
    }
});

test('move keeps the source of every object it skipped', function () {
    // Deleting the whole source tree because "something was copied" destroyed
    // the originals of files that were never transferred.
    $src = local_fixture_base();
    $dst = local_fixture_base();
    mkdir($src.'/nested', 0o777, true);
    mkdir($dst, 0o777, true);
    file_put_contents($src.'/keep.txt', 'source');
    file_put_contents($src.'/nested/new.txt', 'fresh');
    file_put_contents($dst.'/keep.txt', 'existing');

    try {
        $result = local_driver()->move(local_path($src.'/'), local_path($dst.'/'));

        expect($result->status)->toBe(TransferStatus::Success)
            ->and($result->copied)->toBe(1)
            ->and($result->skipped)->toBe(1)
            ->and(file_get_contents($dst.'/keep.txt'))->toBe('existing')
            ->and(file_get_contents($src.'/keep.txt'))->toBe('source')
            ->and(file_exists($src.'/nested/new.txt'))->toBeFalse()
            ->and(file_get_contents($dst.'/nested/new.txt'))->toBe('fresh');
    } finally {
        remove_tree_lsd($src);
        remove_tree_lsd($dst);
    }
});

test('move clears the source tree when everything transferred', function () {
    $src = local_fixture_base();
    $dst = local_fixture_base();
    mkdir($src.'/nested', 0o777, true);
    file_put_contents($src.'/a.txt', 'one');
    file_put_contents($src.'/nested/b.txt', 'two');

    try {
        $result = local_driver()->move(local_path($src.'/'), local_path($dst.'/'));

        expect($result->copied)->toBe(2)
            ->and(is_dir($src))->toBeFalse()
            ->and(file_get_contents($dst.'/nested/b.txt'))->toBe('two');
    } finally {
        remove_tree_lsd($src);
        remove_tree_lsd($dst);
    }
});

test('rename moves a file and creates missing parents', function () {
    $src = local_fixture_base();
    $dst = local_fixture_base();
    mkdir($src, 0o777, true);
    file_put_contents($src.'/a.txt', 'renamed');

    try {
        $result = local_driver()->rename(local_path($src.'/a.txt'), local_path($dst.'/deep/b.txt'));
        expect($result->status)->toBe(TransferStatus::Success)
            ->and(file_get_contents($dst.'/deep/b.txt'))->toBe('renamed')
            ->and(file_exists($src.'/a.txt'))->toBeFalse();
    } finally {
        remove_tree_lsd($src);
        remove_tree_lsd($dst);
    }
});

test('visibility applies public and private modes', function () {
    $base = local_fixture_base();
    mkdir($base, 0o777, true);
    file_put_contents($base.'/a.txt', 'x');

    try {
        local_driver()->visibility(local_path($base.'/a.txt'), 'public');
        expect(fileperms($base.'/a.txt') & 0o777)->toBe(0o644);

        local_driver()->visibility(local_path($base.'/a.txt'), 'private');
        expect(fileperms($base.'/a.txt') & 0o777)->toBe(0o600);
    } finally {
        remove_tree_lsd($base);
    }
});

test('visibility dry run leaves permissions untouched', function () {
    $base = local_fixture_base();
    mkdir($base, 0o777, true);
    file_put_contents($base.'/a.txt', 'x');
    chmod($base.'/a.txt', 0o644);
    $before = fileperms($base.'/a.txt');

    try {
        $result = local_driver()->visibility(local_path($base.'/a.txt'), 'public', new TransferOptions(dryRun: true));
        expect($result->status)->toBe(TransferStatus::Success)
            ->and(fileperms($base.'/a.txt'))->toBe($before);
    } finally {
        remove_tree_lsd($base);
    }
});

test('visibility fails with source-not-found for a missing path', function () {
    $base = local_fixture_base();

    try {
        local_driver()->visibility(local_path($base.'/missing.txt'), 'public');
        $this->fail('Expected an exception.');
    } catch (ObjectNotFoundException) {
        expect(true)->toBeTrue();
    } finally {
        if (is_dir($base)) {
            rmdir($base);
        }
    }
});

test('logs each operation when a logger is provided', function () {
    $src = local_fixture_base();
    $dst = local_fixture_base();
    $logDir = sys_get_temp_dir().'/lsd-log-'.bin2hex(random_bytes(4));
    mkdir($src, 0o777, true);
    file_put_contents($src.'/a.txt', 'x');
    $logger = new StorageLogger($logDir);

    try {
        $driver = new LocalStorageDriver($logger);
        $driver->copy(local_path($src.'/a.txt'), local_path($dst.'/a.txt'));

        $contents = file_get_contents($logDir.'/storage-'.date('Y-m-d').'.log');
        expect($contents)->toContain('copy source='.$src.'/a.txt status=success copied=1');
    } finally {
        remove_tree_lsd($src);
        remove_tree_lsd($dst);
        remove_tree_lsd($logDir);
    }
});

function remove_tree_lsd(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            remove_tree_lsd($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
