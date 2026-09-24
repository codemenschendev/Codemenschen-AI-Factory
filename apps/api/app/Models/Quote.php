<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Quote extends Model
{
    /** The website preview this quote is for (kind site). */
    public function prototype(): BelongsTo
    {
        return $this->belongsTo(Prototype::class);
    }

    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'features' => 'array',
        'breakdown' => 'array',
        'valid_until' => 'datetime',
        'ad_click' => 'array',
    ];
}
