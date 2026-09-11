<?php

declare(strict_types=1);

use App\Drivers\RcloneStorageDriver;
use App\DTOs\StoragePath;
use App\DTOs\TransferOptions;
use App\DTOs\TransferStatus;
use App\Exceptions\TransferException;
use App\Support\ProcessResult;
use App\Support\RcloneProcess;
use App\Support\StorageLogger;
use Tests\TestCase;

// Boots the app: the driver resolves the visibility map through config().
uses(TestCase::class);

/**
 * Records the rclone invocations a driver makes and replays canned results
 * keyed by the rclone subcommand, so backend semantics can be asserted without
 * touching a real remote and without depending on call order.
 */
final class FakeRcloneProcess extends RcloneProcess
{
    /** @var array<int, array<int, string>> */
    public array $calls = [];

    /** @param array<string, ProcessResult> $responses */
    public function __construct(private array $responses = [], private ?ProcessResult $fallback = null)
    {
        parent::__construct('rclone', 10);
    }

    public function run(array $arguments, ?callable $onOutput = null): ProcessResult
    {
        $this->calls[] = $arguments;

        return $this->responses[$arguments[0] ?? '']
            ?? $this->fallback
            ?? new ProcessResult(0, '', '');
    }

    /** @return array<int, array<int, string>> */
    public function callsFor(string $verb): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => ($call[0] ?? '') === $verb));
    }

    public function commandFor(string $verb): ?array
    {
        foreach ($this->calls as $call) {
            if (($call[0] ?? null) === $verb) {
                return $call;
            }
        }

        return null;
    }
}

/**
 * @param  array<string, ProcessResult>  $responses  keyed by rclone subcommand
 */
function rclone_driver_fixture(array $responses = [], ?ProcessResult $fallback = null): array
{
    $responses += [
        'version' => new ProcessResult(0, "rclone v1.74.2\n", ''),
        'listremotes' => new ProcessResult(0, "rem:\n", ''),
        'config' => new ProcessResult(0, "[rem]\ntype = s3\nacl = private\n", ''),
    ];

    $process = new FakeRcloneProcess($responses, $fallback);
    $logger = new StorageLogger(sys_get_temp_dir().'/storage-rsd-'.bin2hex(random_bytes(4)));

    return [new RcloneStorageDriver($process, $logger), $process];
}

function rclone_stats_line(array $stats): string
{
    return json_encode(['level' => 'notice', 'msg' => 'stats', 'stats' => $stats]).PHP_EOL;
}

test('exists() is false when rclone lists nothing for the path', function () {
    // rclone exits 0 with empty output for a prefix that is not there; treating
    // the exit code as the answer marked every destination as occupied.
    [$driver, $process] = rclone_driver_fixture(['lsf' => new ProcessResult(0, '', '')]);

    expect($driver->exists(StoragePath::fromString('rem:bucket/missing/')))->toBeFalse()
        ->and($process->commandFor('lsf'))->toContain('rem:bucket/missing/');
});

test('exists() is true when rclone lists the object', function () {
    [$driver] = rclone_driver_fixture(['lsf' => new ProcessResult(0, "a.txt\n", '')]);

    expect($driver->exists(StoragePath::fromString('rem:bucket/a.txt')))->toBeTrue();
});

test('exists() is false when rclone fails', function () {
    [$driver] = rclone_driver_fixture(['lsf' => new ProcessResult(3, '', 'directory not found')]);

    expect($driver->exists(StoragePath::fromString('rem:nosuchbucket/')))->toBeFalse();
});

test('copy reports the counts rclone actually recorded', function () {
    [$driver, $process] = rclone_driver_fixture([
        'copy' => new ProcessResult(0, '', rclone_stats_line([
            'transfers' => 7, 'checks' => 9, 'errors' => 0, 'bytes' => 100, 'totalBytes' => 100,
        ])),
    ]);

    $result = $driver->copy(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('rem:bucket/dst/'),
    );

    expect($result->status)->toBe(TransferStatus::Success)
        ->and($result->copied)->toBe(7)
        ->and($result->skipped)->toBe(2)
        ->and($process->commandFor('copy'))->toContain('--ignore-existing');
});

test('move issues a single rclone move for a prefix', function () {
    [$driver, $process] = rclone_driver_fixture([
        'move' => new ProcessResult(0, '', rclone_stats_line([
            'transfers' => 4370, 'checks' => 0, 'errors' => 0, 'bytes' => 1024, 'totalBytes' => 1024,
        ])),
    ]);

    $result = $driver->move(
        StoragePath::fromString('rem:bucket/za_ticket/'),
        StoragePath::fromString('rem:bucket/_trash/'),
    );

    $move = $process->commandFor('move');

    expect($result->copied)->toBe(4370)
        ->and($result->status)->toBe(TransferStatus::Success)
        ->and($move)->not->toBeNull()
        ->and($move)->toContain('--delete-empty-src-dirs')
        ->and($process->commandFor('copy'))->toBeNull()
        ->and($process->commandFor('delete'))->toBeNull();
});

test('a verified move does not report its own checks as skips', function () {
    // move/moveto checks every object at the destination before deleting the
    // source, so rclone reports checks == transfers on a clean rename. Those
    // are verifications, not objects left behind.
    [$driver] = rclone_driver_fixture([
        'move' => new ProcessResult(0, '', rclone_stats_line([
            'transfers' => 4370, 'checks' => 4370, 'errors' => 0, 'bytes' => 1024, 'totalBytes' => 1024,
        ])),
    ]);

    $result = $driver->rename(
        StoragePath::fromString('rem:bucket/za_ticket/'),
        StoragePath::fromString('rem:bucket/_trash-za_ticket/'),
    );

    expect($result->copied)->toBe(4370)
        ->and($result->skipped)->toBe(0)
        ->and($result->total())->toBe(4370)
        ->and($result->status)->toBe(TransferStatus::Success);
});

test('move of a single object uses moveto', function () {
    [$driver, $process] = rclone_driver_fixture([
        'moveto' => new ProcessResult(0, '', rclone_stats_line([
            'transfers' => 1, 'checks' => 0, 'errors' => 0, 'bytes' => 4, 'totalBytes' => 4,
        ])),
    ]);

    $driver->move(
        StoragePath::fromString('rem:bucket/a.txt'),
        StoragePath::fromString('rem:bucket/b.txt'),
    );

    expect($process->commandFor('moveto'))->not->toBeNull();
});

test('a run that transferred some objects and hit errors is partial', function () {
    [$driver] = rclone_driver_fixture([
        'copy' => new ProcessResult(1, '', implode('', [
            json_encode(['level' => 'error', 'msg' => 'permission denied', 'object' => 'x.txt']).PHP_EOL,
            rclone_stats_line(['transfers' => 5, 'checks' => 0, 'errors' => 1, 'bytes' => 5, 'totalBytes' => 6]),
        ])),
    ]);

    $result = $driver->copy(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('rem:bucket/dst/'),
    );

    expect($result->status)->toBe(TransferStatus::Partial)
        ->and($result->copied)->toBe(5)
        ->and($result->failed)->toBe(1)
        ->and($result->errors)->toBe(['x.txt: permission denied']);
});

test('a clean run reports no errors even when rclone logged at error level', function () {
    [$driver] = rclone_driver_fixture([
        'copy' => new ProcessResult(0, '', implode('', [
            json_encode(['level' => 'error', 'msg' => 'Entry doesn\'t belong in directory "" (same as directory) - ignoring', 'object' => '']).PHP_EOL,
            rclone_stats_line(['transfers' => 0, 'checks' => 78, 'errors' => 0, 'bytes' => 0, 'totalBytes' => 0]),
        ])),
    ]);

    $result = $driver->copy(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('rem:bucket/dst/'),
    );

    expect($result->status)->toBe(TransferStatus::Success)
        ->and($result->skipped)->toBe(78)
        ->and($result->errors)->toBe([]);
});

test('the rclone binary is probed once per driver instance', function () {
    [$driver, $process] = rclone_driver_fixture([], new ProcessResult(0, "rem:\n", ''));

    $driver->isAvailable();
    $driver->isAvailable();
    $driver->remotes();
    $driver->remotes();

    $versions = array_filter($process->calls, static fn (array $call): bool => ($call[0] ?? '') === 'version');
    $listings = array_filter($process->calls, static fn (array $call): bool => ($call[0] ?? '') === 'listremotes');

    expect($versions)->toHaveCount(1)
        ->and($listings)->toHaveCount(1);
});

test('a remote configured with a non-canned acl has it mapped to a valid one', function () {
    // S3 rejects "acl = public" on CopyObject with an opaque 400
    // InvalidArgument, which failed every object of a prefix move.
    [$driver, $process] = rclone_driver_fixture([
        'config' => new ProcessResult(0, "[rem]\ntype = s3\nacl = public\n", ''),
        'move' => new ProcessResult(0, '', rclone_stats_line([
            'transfers' => 3, 'checks' => 0, 'errors' => 0, 'bytes' => 9, 'totalBytes' => 9,
        ])),
    ]);

    $driver->move(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('rem:bucket/dst/'),
    );

    expect($process->commandFor('move'))->toContain('--s3-acl=public-read');
});

test('a canned acl on the remote is left alone', function () {
    [$driver, $process] = rclone_driver_fixture([
        'config' => new ProcessResult(0, "[rem]\ntype = s3\nacl = public-read\n", ''),
        'copy' => new ProcessResult(0, '', rclone_stats_line(['transfers' => 1, 'checks' => 0, 'errors' => 0])),
    ]);

    $driver->copy(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('rem:bucket/dst/'),
    );

    $acl = array_filter($process->commandFor('copy'), static fn (string $arg): bool => str_starts_with($arg, '--s3-acl='));

    expect($acl)->toBeEmpty();
});

test('an explicit --acl is mapped and takes precedence over the remote config', function () {
    [$driver, $process] = rclone_driver_fixture([
        'config' => new ProcessResult(0, "[rem]\ntype = s3\nacl = public\n", ''),
        'copy' => new ProcessResult(0, '', rclone_stats_line(['transfers' => 1, 'checks' => 0, 'errors' => 0])),
    ]);

    $driver->copy(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('rem:bucket/dst/'),
        new TransferOptions(acl: 'private'),
    );

    expect($process->commandFor('copy'))->toContain('--s3-acl=private');
});

test('an unmappable acl is reported before anything is transferred', function () {
    [$driver, $process] = rclone_driver_fixture([
        'config' => new ProcessResult(0, "[rem]\ntype = s3\nacl = totally-made-up\n", ''),
    ]);

    expect(fn () => $driver->copy(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('rem:bucket/dst/'),
    ))->toThrow(TransferException::class, 'not a valid S3 ACL');

    expect($process->callsFor('copy'))->toBeEmpty();
});

test('the acl of a local destination is never consulted', function () {
    [$driver, $process] = rclone_driver_fixture([
        'copy' => new ProcessResult(0, '', rclone_stats_line(['transfers' => 1, 'checks' => 0, 'errors' => 0])),
    ]);

    $driver->copy(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('/tmp/dst/'),
    );

    expect($process->callsFor('config'))->toBeEmpty();
});

test('the remote config is read once per driver instance', function () {
    [$driver, $process] = rclone_driver_fixture([
        'config' => new ProcessResult(0, "[rem]\ntype = s3\nacl = public\n", ''),
        'copy' => new ProcessResult(0, '', rclone_stats_line(['transfers' => 1, 'checks' => 0, 'errors' => 0])),
    ]);

    $source = StoragePath::fromString('rem:bucket/src/');
    $destination = StoragePath::fromString('rem:bucket/dst/');

    $driver->copy($source, $destination);
    $driver->copy($source, $destination);

    expect($process->callsFor('config'))->toHaveCount(1);
});

test('a configured default acl wins over the remote config', function () {
    // storage.defaults.acl reaches the driver as TransferOptions::$acl via the
    // --acl option default, so the remote's own acl is never consulted.
    [$driver, $process] = rclone_driver_fixture([
        'config' => new ProcessResult(0, "[rem]\ntype = s3\nacl = public\n", ''),
        'copy' => new ProcessResult(0, '', rclone_stats_line(['transfers' => 1, 'checks' => 0, 'errors' => 0])),
    ]);

    $driver->copy(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('rem:bucket/dst/'),
        new TransferOptions(acl: 'private'),
    );

    expect($process->commandFor('copy'))->toContain('--s3-acl=private')
        ->and($process->callsFor('config'))->toBeEmpty();
});

test('an application-level default acl is mapped to a canned one', function () {
    [$driver, $process] = rclone_driver_fixture([
        'copy' => new ProcessResult(0, '', rclone_stats_line(['transfers' => 1, 'checks' => 0, 'errors' => 0])),
    ]);

    $driver->copy(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('rem:bucket/dst/'),
        new TransferOptions(acl: 'public'),
    );

    expect($process->commandFor('copy'))->toContain('--s3-acl=public-read');
});

test('an invalid default acl is reported before anything is transferred', function () {
    [$driver, $process] = rclone_driver_fixture();

    expect(fn () => $driver->copy(
        StoragePath::fromString('rem:bucket/src/'),
        StoragePath::fromString('rem:bucket/dst/'),
        new TransferOptions(acl: 'made-up'),
    ))->toThrow(TransferException::class, 'The requested ACL "made-up"');

    expect($process->callsFor('copy'))->toBeEmpty();
});
