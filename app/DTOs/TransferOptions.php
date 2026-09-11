<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Transport options shared by transfer-type operations.
 */
final readonly class TransferOptions
{
    public function __construct(
        public int $transfers = 8,
        public int $retries = 3,
        public bool $dryRun = false,
        public bool $verbose = false,
        public bool $overwrite = false,
        public bool $recursive = false,
        public bool $progress = false,
        public ?string $acl = null,
    ) {}
}
