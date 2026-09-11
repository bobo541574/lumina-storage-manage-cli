<?php

declare(strict_types=1);

namespace App\DTOs;

enum TransferStatus: string
{
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
}
