<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Prototype extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime', 'qa' => 'array'];

    /** The page went out with something a browser could see was wrong. Null means nobody looked. */
    public function qaFailed(): bool
    {
        return ($this->qa['ok'] ?? null) === false;
    }

    public function isLive(): bool
    {
        return $this->status === 'ready' && $this->expires_at->isFuture();
    }
}
