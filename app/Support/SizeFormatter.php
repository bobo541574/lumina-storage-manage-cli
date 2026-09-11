<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Render byte counts in a compact human-readable form (binary units).
 */
final class SizeFormatter
{
    private const UNITS = ['B', 'K', 'M', 'G', 'T', 'P'];

    public static function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.'B';
        }

        $value = (float) $bytes;
        $unit = 0;

        // Stop at the largest unit rather than overflowing past it, so a
        // petabyte-scale bucket does not render as "1048576.0T".
        while ($value >= 1024 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f%s', $value, self::UNITS[$unit]);
    }
}
