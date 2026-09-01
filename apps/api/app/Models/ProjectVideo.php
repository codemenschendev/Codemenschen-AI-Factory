<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectVideo extends Model
{
    protected $guarded = [];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Absolute path on disk. `path` is stored relative so the media directory can move. */
    public function absolutePath(): string
    {
        return rtrim((string) config('services.media.videos_path'), '/').'/'.ltrim($this->path, '/');
    }
}
