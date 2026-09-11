<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A single entry yielded by a storage listing.
 */
final readonly class ListingEntry
{
    public function __construct(
        public string $name,
        public string $path,
        public int $size = 0,
        public bool $isDirectory = false,
        public bool $isFile = false,
    ) {}
}
