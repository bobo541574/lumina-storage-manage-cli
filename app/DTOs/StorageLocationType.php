<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The type of a storage location.
 */
enum StorageLocationType: string
{
    case Remote = 'remote';
    case Local = 'local';
}
