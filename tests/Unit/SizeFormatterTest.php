<?php

declare(strict_types=1);

use App\Support\SizeFormatter;

test('renders plain bytes below one KiB', function () {
    expect(SizeFormatter::human(0))->toBe('0B')
        ->and(SizeFormatter::human(1))->toBe('1B')
        ->and(SizeFormatter::human(1023))->toBe('1023B');
});

test('renders KiB with one decimal place', function () {
    expect(SizeFormatter::human(1024))->toBe('1.0K')
        ->and(SizeFormatter::human(1536))->toBe('1.5K')
        ->and(SizeFormatter::human(1048575))->toBe('1024.0K');
});

test('renders MiB with one decimal place', function () {
    expect(SizeFormatter::human(1048576))->toBe('1.0M')
        ->and(SizeFormatter::human(2097152))->toBe('2.0M');
});

test('renders GiB with one decimal place', function () {
    expect(SizeFormatter::human(1073741824))->toBe('1.0G')
        ->and(SizeFormatter::human(2147483648))->toBe('2.0G');
});

test('renders TiB and PiB instead of overflowing the largest unit', function () {
    expect(SizeFormatter::human(1099511627776))->toBe('1.0T')
        ->and(SizeFormatter::human(1125899906842624))->toBe('1.0P')
        ->and(SizeFormatter::human(2251799813685248))->toBe('2.0P');
});
