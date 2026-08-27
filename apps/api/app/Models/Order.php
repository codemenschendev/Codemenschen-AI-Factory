<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasUuids;

    /**
     * Store-listing languages the assets stage can produce. Adding a language
     * here is all the backend needs; the web dictionaries need a label for it.
     */
    public const SUPPORTED_STORE_LOCALES = ['de', 'en'];

    protected $guarded = [];

    protected $casts = [
        'packages' => 'array',
        'store_locales' => 'array',
        'fagg_waiver' => 'boolean',
        'fagg_waiver_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return string[] */
    public function storeLocales(): array
    {
        return $this->store_locales ?: self::SUPPORTED_STORE_LOCALES;
    }
}
