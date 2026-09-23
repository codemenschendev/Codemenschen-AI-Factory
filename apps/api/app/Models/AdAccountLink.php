<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's ad account, and how far the link to ours has come.
 *
 * `pending` means we asked and the customer has not pressed accept yet. `active` means the
 * platform itself confirmed it, not that we hope so: every status here was read back from Google
 * or Meta, never assumed from our own request succeeding.
 */
class AdAccountLink extends Model
{
    protected $fillable = ['customer_id', 'platform', 'external_id', 'page_id', 'page_name', 'status', 'name',
        'manager_link_id', 'requested_at', 'activated_at', 'checked_at', 'error'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'activated_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isLive(): bool
    {
        return $this->status === 'active';
    }
}
