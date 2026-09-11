<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persisted configuration profiles for the wizard and reusable transfers
 * (legacy "saved configurations" parity), stored in the application database.
 */
class SavedConfig extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }
}
