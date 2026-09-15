<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One thing a visitor did. See App\Domain\Analytics\Analytics. */
class AnalyticsEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['props' => 'array', 'created_at' => 'datetime'];
}
