<?php

declare(strict_types=1);

namespace App\Support;

final class ExitCode
{
    public const SUCCESS = 0;

    public const FAILURE = 1;

    public const INVALID = 2;

    public const SOURCE_NOT_FOUND = 3;

    public const DESTINATION = 4;

    public const VISIBILITY = 5;

    public const PARTIAL = 6;

    private function __construct() {}
}
