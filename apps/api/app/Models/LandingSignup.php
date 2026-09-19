<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One address on a campaign landing page's waitlist, and the record of its consent. */
class LandingSignup extends Model
{
    protected $guarded = [];

    protected $casts = ['mailed_at' => 'datetime', 'confirmed_at' => 'datetime'];

    public function prototype(): BelongsTo
    {
        return $this->belongsTo(Prototype::class);
    }
}
