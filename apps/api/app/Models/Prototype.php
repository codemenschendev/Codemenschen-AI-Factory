<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prototype extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime', 'published_at' => 'datetime', 'qa' => 'array', 'uploads' => 'array'];

    /** The page went out with something a browser could see was wrong. Null means nobody looked. */
    public function qaFailed(): bool
    {
        return ($this->qa['ok'] ?? null) === false;
    }

    /** The project that bought this page, for a website. */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isLive(): bool
    {
        // A bought page has no expiry.
        return $this->status === 'ready' && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
