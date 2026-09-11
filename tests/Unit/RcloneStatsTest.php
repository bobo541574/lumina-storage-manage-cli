<?php

declare(strict_types=1);

use App\Support\RcloneStats;

test('reads the final stats block from the json log', function () {
    $log = implode("\n", [
        '{"level":"info","msg":"Copied","object":"a.txt"}',
        '{"level":"notice","msg":"stats","stats":{"transfers":1,"checks":0,"errors":0,"bytes":10,"totalBytes":20,"deletes":0,"elapsedTime":0.5}}',
        '{"level":"notice","msg":"stats","stats":{"transfers":3,"checks":2,"errors":0,"bytes":20,"totalBytes":20,"deletes":1,"elapsedTime":1.25}}',
    ]);

    $stats = RcloneStats::parse($log);

    expect($stats->present)->toBeTrue()
        ->and($stats->transfers)->toBe(3)
        ->and($stats->checks)->toBe(2)
        ->and($stats->deletes)->toBe(1)
        ->and($stats->errors)->toBe(0)
        ->and($stats->progress())->toBe(1.0);
});

test('collects error lines and the last error', function () {
    $log = implode("\n", [
        '{"level":"error","msg":"permission denied","object":"secret.txt"}',
        '{"level":"notice","msg":"stats","stats":{"transfers":0,"checks":0,"errors":1,"bytes":0,"totalBytes":0,"lastError":"permission denied"}}',
    ]);

    $stats = RcloneStats::parse($log);

    expect($stats->errors)->toBe(1)
        ->and($stats->errorMessages)->toBe(['secret.txt: permission denied'])
        ->and($stats->progress())->toBeNull();
});

test('reports absent stats when rclone produced none', function () {
    $stats = RcloneStats::parse("rclone: command not found\n");

    expect($stats->present)->toBeFalse()
        ->and($stats->errorMessages)->toBe(['rclone: command not found']);
});

test('groups the same failure across objects and retries into one line', function () {
    // A prefix-wide failure produced ~6 log lines per object (retries plus a
    // per-attempt summary), each carrying a unique RequestID so nothing
    // deduplicated: 4370 failed objects rendered 26224 error strings.
    $lines = [];

    foreach (['a.png', 'b.pdf', 'c.jpg'] as $object) {
        foreach ([1, 2, 3] as $attempt) {
            $lines[] = json_encode([
                'level' => 'error',
                'object' => $object,
                'msg' => 'Failed to copy: operation error S3: CopyObject, https response error StatusCode: 400, '
                    .'RequestID: tx'.$attempt.$object.', HostID: h'.$attempt.$object.', api error InvalidArgument: UnknownError',
            ]);
            $lines[] = json_encode([
                'level' => 'error',
                'msg' => 'Attempt '.$attempt.'/3 failed with 3 errors and: InvalidArgument',
            ]);
        }
    }

    $lines[] = json_encode(['level' => 'notice', 'msg' => 'stats', 'stats' => [
        'transfers' => 0, 'checks' => 0, 'errors' => 3, 'bytes' => 0, 'totalBytes' => 0,
    ]]);

    $stats = RcloneStats::parse(implode("\n", $lines));

    expect($stats->errorMessages)->toHaveCount(1)
        ->and($stats->errorMessages[0])->toContain('api error InvalidArgument: UnknownError')
        ->and($stats->errorMessages[0])->toContain('(3 objects, e.g. a.png)')
        ->and($stats->errorMessages[0])->not->toContain('RequestID')
        ->and($stats->errorMessages[0])->not->toContain('Attempt 1/3');
});

test('a failure affecting one object names that object', function () {
    $log = implode("\n", [
        json_encode(['level' => 'error', 'object' => 'only.txt', 'msg' => 'permission denied']),
        json_encode(['level' => 'error', 'object' => 'only.txt', 'msg' => 'permission denied']),
        json_encode(['level' => 'notice', 'msg' => 'stats', 'stats' => ['transfers' => 0, 'errors' => 1]]),
    ]);

    expect(RcloneStats::parse($log)->errorMessages)->toBe(['only.txt: permission denied']);
});

test('listing artefacts rclone ignored are not reported as errors', function () {
    // rclone logs this at error level, ignores the entry and counts nothing in
    // stats.errors: an S3 directory-marker object whose key IS the prefix being
    // listed trips it on every run over that prefix.
    $log = implode("\n", [
        json_encode([
            'level' => 'error',
            'msg' => 'Entry doesn\'t belong in directory "" (same as directory) - ignoring',
            'object' => '',
            'objectType' => '*s3.Object',
        ]),
        json_encode(['level' => 'notice', 'msg' => 'stats', 'stats' => ['transfers' => 78, 'checks' => 78, 'errors' => 0]]),
    ]);

    $stats = RcloneStats::parse($log);

    expect($stats->errors)->toBe(0)
        ->and($stats->errorMessages)->toBe([]);
});
