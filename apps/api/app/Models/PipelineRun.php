<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineRun extends Model
{
    use HasUuids;

    public const STAGES = ['product', 'uiux', 'coding', 'test', 'fix', 'release', 'assets'];

    protected $guarded = [];

    protected $casts = [
        'output' => 'array',
        'started_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $hidden = ['callback_token'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
