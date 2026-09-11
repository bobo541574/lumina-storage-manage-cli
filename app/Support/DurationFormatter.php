<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Render elapsed seconds in a compact, human-readable form.
 */
final class DurationFormatter
{
    public static function human(float $seconds): string
    {
        if ($seconds < 1) {
            return sprintf('%dms', max(1, (int) round($seconds * 1000)));
        }

        if ($seconds < 60) {
            return rtrim(rtrim(sprintf('%.1f', $seconds), '0'), '.').'s';
        }

        $minutes = (int) floor($seconds / 60);
        $remainder = (int) round($seconds - ($minutes * 60));

        if ($remainder === 60) {
            $minutes++;
            $remainder = 0;
        }

        if ($minutes < 60) {
            return sprintf('%dm %02ds', $minutes, $remainder);
        }

        $hours = intdiv($minutes, 60);

        return sprintf('%dh %02dm %02ds', $hours, $minutes % 60, $remainder);
    }
}
