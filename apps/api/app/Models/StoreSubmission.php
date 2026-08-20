<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSubmission extends Model
{
    public const STORES = ['apple', 'google'];

    public const STATUSES = ['waiting_account', 'preparing', 'submitted', 'in_review', 'live', 'rejected'];

    protected $guarded = [];

    protected $casts = ['submitted_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
