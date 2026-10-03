<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Sofabuilt desk chat: the idea, the scope the agent keeps, what the research found. */
class DeskSession extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'scope' => 'array',
        'research' => 'array',
        'ready' => 'boolean',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(DeskMessage::class)->orderBy('id');
    }
}
