<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

    /** Pipeline statuses (PLAN.md §4). Transitions are enforced centrally. */
    public const STATUSES = [
        'IDEA', 'QUOTED', 'PAID', 'SPECIFICATION', 'BUILDING', 'TESTING',
        'FIXING', 'REVIEW', 'READY', 'PUBLISHING', 'PUBLISHED', 'MARKETING',
        'COMPLETED', 'FAILED',
    ];

    protected $guarded = [];

    protected $casts = ['build_starts_at' => 'datetime', 'care_started_at' => 'datetime', 'care_ends_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProjectEvent::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PipelineRun::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(ProjectAd::class);
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(AcceptanceCriterion::class);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    public function testReports(): HasMany
    {
        return $this->hasMany(TestReport::class);
    }

    public function storeAssets(): HasMany
    {
        return $this->hasMany(StoreAsset::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(StoreSubmission::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }

    /** Browser preview of the latest build, when the release stage exported one. */
    public function previewUrl(): ?string
    {
        if (! $this->builds()->where('platform', 'web')->exists()) {
            return null;
        }

        return rtrim(config('app.url'), '/')."/api/preview/{$this->id}/";
    }

    public function recordEvent(string $type, array $payload = [], string $actor = 'system'): void
    {
        $this->events()->create(['type' => $type, 'payload' => $payload, 'actor' => $actor]);
    }
}
