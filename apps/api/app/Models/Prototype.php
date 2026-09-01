<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Prototype extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime'];

    public function isLive(): bool
    {
        return $this->status === 'ready' && $this->expires_at->isFuture();
    }
}
