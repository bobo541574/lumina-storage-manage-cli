<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The result of a storage listing operation.
 */
final readonly class ListingResult
{
    /** @param array<int, ListingEntry> $entries */
    public function __construct(
        public array $entries = [],
    ) {}

    public function count(): int
    {
        return count($this->entries);
    }

    public function totalSize(): int
    {
        return array_sum(array_map(
            static fn (ListingEntry $entry): int => $entry->isDirectory ? 0 : $entry->size,
            $this->entries,
        ));
    }
}
