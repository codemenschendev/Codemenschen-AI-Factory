<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeRequest extends Model
{
    protected $guarded = [];

    protected $casts = ['paid_at' => 'datetime', 'fagg_waiver_at' => 'datetime', 'items' => 'array', 'result_items' => 'array'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
