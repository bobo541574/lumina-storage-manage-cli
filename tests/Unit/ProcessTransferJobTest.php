<?php

declare(strict_types=1);

use App\DTOs\TransferResult;
use App\DTOs\TransferStatus;
use App\Exceptions\StorageException;
use App\Jobs\ProcessTransferJob;
use App\Services\StorageService;
use App\Services\TransferService;
use App\Services\VisibilityService;
use Psr\Log\LoggerInterface;

test('queued copy job runs the transfer and logs success', function () {
    $transfers = mock(TransferService::class);

    $transfers->shouldReceive('copy')
        ->once()
        ->with(
            Mockery::on(fn ($p) => $p->toDisplayString() === '/tmp/job-src'),
            Mockery::on(fn ($p) => $p->toDisplayString() === '/tmp/job-dst'),
            Mockery::on(fn ($o) => $o->overwrite === true),
        )
        ->andReturn(new TransferResult(status: TransferStatus::Success, copied: 2, skipped: 1));

    $log = mock(LoggerInterface::class);
    $log->shouldReceive('info')->once()->withArgs(function ($message) {
        return str_contains($message, 'copied=2') && str_contains($message, 'status=success')
            && str_contains($message, 'operation=copy');
    });

    $job = new ProcessTransferJob('copy', '/tmp/job-src', '/tmp/job-dst', ['overwrite' => true]);

    $job->handle($transfers, mock(StorageService::class), mock(VisibilityService::class), $log);
});

test('queued job with failures throws so it lands in failed_jobs', function () {
    $transfers = mock(TransferService::class);

    $transfers->shouldReceive('copy')
        ->once()
        ->andReturn(new TransferResult(status: TransferStatus::Partial, copied: 0, failed: 2, errors: ['boom']));

    $job = new ProcessTransferJob('copy', '/tmp/job-src', '/tmp/job-dst');

    expect(fn () => $job->handle(
        $transfers,
        mock(StorageService::class),
        mock(VisibilityService::class),
        mock(LoggerInterface::class),
    ))->toThrow(StorageException::class, 'Queued copy failed: 2 object(s) failed');
});

test('queued destination operation without destination is rejected', function () {
    $job = new ProcessTransferJob('copy', '/tmp/job-src', null);

    expect(fn () => $job->handle(
        mock(TransferService::class),
        mock(StorageService::class),
        mock(VisibilityService::class),
        mock(LoggerInterface::class),
    ))->toThrow(InvalidArgumentException::class, 'requires a destination');
});
