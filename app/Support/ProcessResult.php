<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Immutable outcome of an executed process.
 */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function combine(): string
    {
        $out = trim($this->stdout);

        if ($out !== '') {
            return $out;
        }

        return trim($this->stderr);
    }
}
