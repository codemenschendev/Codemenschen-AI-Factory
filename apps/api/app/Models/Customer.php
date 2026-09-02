<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens;

    protected $guarded = [];

    protected $casts = ['is_admin' => 'boolean'];

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
