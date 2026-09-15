<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a project's change chat. See App\Services\ChangeChat. */
class ChangeMessage extends Model
{
    protected $guarded = [];

    protected $casts = ['meta' => 'array'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
    }
}
