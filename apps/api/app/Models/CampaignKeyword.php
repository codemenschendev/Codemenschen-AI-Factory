<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignKeyword extends Model
{
    protected $guarded = [];

    protected $casts = ['negative' => 'boolean', 'applied_at' => 'datetime'];

    /** What Google is told: "shoes" for phrase, [shoes] for exact. */
    public function criterion(): array
    {
        return ['text' => $this->text, 'matchType' => $this->match_type === 'exact' ? 'EXACT' : 'PHRASE'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }
}
