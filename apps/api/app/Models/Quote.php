<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'features' => 'array',
        'breakdown' => 'array',
        'valid_until' => 'datetime',
    ];
}
