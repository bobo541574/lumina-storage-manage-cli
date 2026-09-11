<?php

declare(strict_types=1);

use App\Support\DurationFormatter;

test('renders sub-second durations in milliseconds', function () {
    expect(DurationFormatter::human(0.25))->toBe('250ms')
        ->and(DurationFormatter::human(0.0001))->toBe('1ms');
});

test('renders seconds without trailing zeros', function () {
    expect(DurationFormatter::human(1.0))->toBe('1s')
        ->and(DurationFormatter::human(12.5))->toBe('12.5s');
});

test('renders minutes and hours', function () {
    expect(DurationFormatter::human(75))->toBe('1m 15s')
        ->and(DurationFormatter::human(3725))->toBe('1h 02m 05s');
});
