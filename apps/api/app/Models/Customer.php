<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens;

    protected $guarded = [];

    protected $casts = [
        'is_admin' => 'boolean',
        'two_factor_secret' => 'encrypted',
        'two_factor_enabled_at' => 'datetime',
        'two_factor_recovery' => 'array',
    ];

    protected $hidden = ['two_factor_secret', 'two_factor_recovery', 'two_factor_last_step'];

    /** The operator lane: sees every project in the factory and can push a stuck one along. */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
