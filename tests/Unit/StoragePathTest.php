<?php

declare(strict_types=1);

use App\DTOs\StorageLocationType;
use App\DTOs\StoragePath;
use App\Exceptions\PathParseException;

test('parses a remote file path', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data/documents/report.pdf');

    expect($path->type())->toBe(StorageLocationType::Remote)
        ->and($path->remote())->toBe('do-spaces-nyc')
        ->and($path->bucket())->toBe('my-data')
        ->and($path->path())->toBe('documents/report.pdf')
        ->and($path->filename())->toBe('report.pdf')
        ->and($path->isRemote())->toBeTrue()
        ->and($path->isLocal())->toBeFalse()
        ->and($path->isDirectory())->toBeFalse()
        ->and($path->isPrefix())->toBeFalse()
        ->and($path->isBucketRoot())->toBeFalse()
        ->and($path->toRclonePath())->toBe('do-spaces-nyc:my-data/documents/report.pdf');
});

test('parses a remote directory prefix', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data/documents/');

    expect($path->type())->toBe(StorageLocationType::Remote)
        ->and($path->remote())->toBe('do-spaces-nyc')
        ->and($path->bucket())->toBe('my-data')
        ->and($path->path())->toBe('documents/')
        ->and($path->isDirectory())->toBeTrue()
        ->and($path->isPrefix())->toBeTrue()
        ->and($path->filename())->toBeNull()
        ->and($path->toRclonePath())->toBe('do-spaces-nyc:my-data/documents/');
});

test('parses a remote bucket root', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data');

    expect($path->remote())->toBe('do-spaces-nyc')
        ->and($path->bucket())->toBe('my-data')
        ->and($path->path())->toBeNull()
        ->and($path->isBucketRoot())->toBeTrue()
        ->and($path->isDirectory())->toBeTrue()
        ->and($path->isPrefix())->toBeFalse()
        ->and($path->toRclonePath())->toBe('do-spaces-nyc:my-data');
});

test('normalizes a trailing slash on a remote bucket root', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data/');

    expect($path->isBucketRoot())->toBeTrue()
        ->and($path->path())->toBeNull()
        ->and($path->toRclonePath())->toBe('do-spaces-nyc:my-data');
});

test('parses an absolute local path', function () {
    $path = StoragePath::fromString('/home/user/report.pdf');

    expect($path->type())->toBe(StorageLocationType::Local)
        ->and($path->isLocal())->toBeTrue()
        ->and($path->remote())->toBeNull()
        ->and($path->bucket())->toBeNull()
        ->and($path->path())->toBe('/home/user/report.pdf')
        ->and($path->filename())->toBe('report.pdf')
        ->and($path->isDirectory())->toBeFalse()
        ->and($path->toRclonePath())->toBe('/home/user/report.pdf');
});

test('parses a relative local path', function () {
    $path = StoragePath::fromString('./downloads/report.pdf');

    expect($path->isLocal())->toBeTrue()
        ->and($path->path())->toBe('./downloads/report.pdf')
        ->and($path->filename())->toBe('report.pdf');
});

test('parses a local directory with trailing slash', function () {
    $path = StoragePath::fromString('/home/user/backups/');

    expect($path->isLocal())->toBeTrue()
        ->and($path->isDirectory())->toBeTrue()
        ->and($path->path())->toBe('/home/user/backups')
        ->and($path->filename())->toBeNull();
});

test('parses an explicit local: prefix', function () {
    $path = StoragePath::fromString('local:/home/user/file.pdf');

    expect($path->isLocal())->toBeTrue()
        ->and($path->path())->toBe('/home/user/file.pdf')
        ->and($path->filename())->toBe('file.pdf')
        ->and($path->toRclonePath())->toBe('/home/user/file.pdf');
});

test('expands a leading tilde in local paths', function () {
    $path = StoragePath::fromString('~/backups/');

    expect($path->isLocal())->toBeTrue()
        ->and($path->isDirectory())->toBeTrue()
        ->and($path->path())->not->toStartWith('~');
});

test('builds a remote path from parts', function () {
    $path = StoragePath::fromRemote('do-spaces-ams', 'backup', 'videos/2026/movie.mp4');

    expect($path->toRclonePath())->toBe('do-spaces-ams:backup/videos/2026/movie.mp4')
        ->and($path->filename())->toBe('movie.mp4');
});

test('builds a remote bucket root from parts', function () {
    $path = StoragePath::fromRemote('do-spaces-ams', 'backup');

    expect($path->isBucketRoot())->toBeTrue()
        ->and($path->toRclonePath())->toBe('do-spaces-ams:backup');
});

test('marks a path as a directory', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data/report.pdf');

    $directory = $path->asDirectory();

    expect($directory->isDirectory())->toBeTrue()
        ->and($directory->toRclonePath())->toBe('do-spaces-nyc:my-data/report.pdf/')
        ->and($path->isDirectory())->toBeFalse();
});

test('appends a child segment to a remote prefix', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data/documents/');

    $child = $path->child('report.pdf');

    expect($child->toRclonePath())->toBe('do-spaces-nyc:my-data/documents/report.pdf')
        ->and($child->filename())->toBe('report.pdf')
        ->and($child->isDirectory())->toBeFalse();
});

test('appends a child segment to a remote bucket root', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data');

    expect($path->child('videos/')->toRclonePath())->toBe('do-spaces-nyc:my-data/videos/')
        ->and($path->child('videos/')->isDirectory())->toBeTrue();
});

test('appends a child segment to a local directory', function () {
    $path = StoragePath::fromString('/home/user/backups/');

    expect($path->child('2026.tar.gz')->path())->toBe('/home/user/backups/2026.tar.gz')
        ->and($path->child('2026.tar.gz')->isLocal())->toBeTrue();
});

test('does not mutate the parent path when appending a child', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data/documents/');

    $child = $path->child('report.pdf');

    expect($path->toRclonePath())->toBe('do-spaces-nyc:my-data/documents/');
    expect($child->toRclonePath())->not->toBe($path->toRclonePath());
});

test('a trailing slash remote prefix keeps its slash in the rclone path', function () {
    $path = StoragePath::fromString('do-spaces-sgp:backup/videos/2026/');

    expect($path->toRclonePath())->toBe('do-spaces-sgp:backup/videos/2026/');
});

test('rejects an empty path', function () {
    StoragePath::fromString('');
})->throws(PathParseException::class);

test('rejects empty spaces only path', function () {
    StoragePath::fromString('   ');
})->throws(PathParseException::class);

test('rejects a remote path without a bucket', function () {
    StoragePath::fromString('remote:');
})->throws(PathParseException::class);

test('rejects an empty bucket when building from parts', function () {
    StoragePath::fromRemote('remote', '');
})->throws(PathParseException::class);

test('child() does not start a path with a double separator', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data/documents');

    expect($path->child('report.pdf')->toRclonePath())
        ->toBe('do-spaces-nyc:my-data/documents/report.pdf');
});

test('child() on a bucket root with a nested segment', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data');

    expect($path->child('docs/2026/')->toRclonePath())
        ->toBe('do-spaces-nyc:my-data/docs/2026/')
        ->and($path->child('docs/2026/')->isDirectory())->toBeTrue();
});

test('parses a remote object key containing spaces', function () {
    $path = StoragePath::fromString('do-spaces-nyc:my-data/My Reports/Q3 final.pdf');

    expect($path->isRemote())->toBeTrue()
        ->and($path->remote())->toBe('do-spaces-nyc')
        ->and($path->bucket())->toBe('my-data')
        ->and($path->path())->toBe('My Reports/Q3 final.pdf')
        ->and($path->toRclonePath())->toBe('do-spaces-nyc:my-data/My Reports/Q3 final.pdf');
});

test('parses a remote prefix containing spaces', function () {
    $path = StoragePath::fromString('rem:bucket/My Reports/');

    expect($path->isDirectory())->toBeTrue()
        ->and($path->toRclonePath())->toBe('rem:bucket/My Reports/');
});
