<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    protected $guarded = [];

    protected $casts = ['strategy' => 'array', 'platform_ref' => 'array', 'published_at' => 'datetime', 'activated_at' => 'datetime'];

    public function creatives(): HasMany
    {
        return $this->hasMany(Creative::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The image or video ad this campaign runs, if one was attached. */
    public function projectAd(): BelongsTo
    {
        return $this->belongsTo(ProjectAd::class);
    }
}
