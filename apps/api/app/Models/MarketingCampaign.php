<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    protected $guarded = [];

    protected $casts = ['strategy' => 'array', 'platform_ref' => 'array', 'published_at' => 'datetime', 'activated_at' => 'datetime',
        'ends_at' => 'datetime', 'spend_checked_at' => 'datetime', 'spent_eur' => 'float', 'spent_today_eur' => 'float'];

    /** The ad file to upload: the project's rendered ad, or the picture of a prototype's ad. */
    public function creativePath(): ?string
    {
        $path = $this->projectAd?->absolutePath() ?? $this->creative_path;

        return $path !== null && is_file($path) ? $path : null;
    }

    public function creativeKind(): string
    {
        return $this->projectAd?->kind === 'video' ? 'video' : 'image';
    }

    /**
     * What the platform may spend in a day. A test with a total and an end spreads the total over
     * its days; a project's monthly budget is a thirtieth a day. Appwerk's own campaigns carry
     * the day amount an admin typed, which wins over both.
     */
    public function dailyEur(): float
    {
        if (($day = $this->strategy['daily_eur'] ?? null) !== null && (float) $day > 0) {
            return round((float) $day, 2);
        }
        if ($this->spend_cap_eur !== null && $this->ends_at !== null) {
            $days = max(1, (int) ceil(now()->diffInHours($this->ends_at, false) / 24));

            return round($this->spend_cap_eur / $days, 2);
        }

        return round((int) $this->ad_budget_monthly_eur / 30, 2);
    }

    /** The utm_campaign our own campaigns carry, so a visit can be traced back to the ad. */
    public function trackingTag(): string
    {
        return 'appwerk-'.$this->id;
    }

    /**
     * Where a click goes. Our own campaigns get UTM parameters added, so the analytics can tell
     * one campaign's visitors from another's; Google's gclid and Meta's fbclid only say where from.
     * A URL that already carries a utm_source is left as the admin wrote it.
     */
    public function finalUrl(): string
    {
        $url = (string) ($this->strategy['landing_url'] ?? 'https://appwerk.codemenschen.at');
        if ($this->project_id !== null || $this->prototype_id !== null || str_contains($url, 'utm_source=')) {
            return $url;
        }
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$source, $medium] = $this->platform === 'meta' ? ['meta', 'paid_social'] : ['google', 'cpc'];
        $query = http_build_query(['utm_source' => $source, 'utm_medium' => $medium, 'utm_campaign' => $this->trackingTag()]);

        return $base.(str_contains($base, '?') ? '&' : '?').$query.($fragment !== null ? '#'.$fragment : '');
    }

    /** Appwerk advertising itself: no customer project and no prototype test behind it. */
    public function scopeOwn(Builder $query): Builder
    {
        return $query->whereNull('project_id')->whereNull('prototype_id');
    }

    public function prototype(): BelongsTo
    {
        return $this->belongsTo(Prototype::class);
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(Creative::class);
    }

    /** The words a search campaign is bought for. Without them it shows nothing. */
    public function keywords(): HasMany
    {
        return $this->hasMany(CampaignKeyword::class, 'campaign_id');
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
