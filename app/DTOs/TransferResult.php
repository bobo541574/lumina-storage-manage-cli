<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Aggregate result of a transfer-type storage operation.
 *
 * Never reduce a partially completed operation to a misleading success status;
 * a non-empty failed count must surface as TransferStatus::Partial or Failed.
 */
final readonly class TransferResult
{
    /** @param array<int, string> $errors */
    public function __construct(
        public TransferStatus $status,
        public int $copied = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public array $errors = [],
        public ?StoragePath $source = null,
        public ?StoragePath $destination = null,
    ) {}

    public static function success(
        int $copied = 0,
        int $skipped = 0,
        ?StoragePath $source = null,
        ?StoragePath $destination = null,
    ): self {
        return new self(TransferStatus::Success, $copied, $skipped, 0, [], $source, $destination);
    }

    public function total(): int
    {
        return $this->copied + $this->skipped + $this->failed;
    }
}
