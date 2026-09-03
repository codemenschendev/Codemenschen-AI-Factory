<?php

namespace App\Jobs;

use App\Domain\Ai\PrototypeWriter;
use App\Domain\Design\DesignRefs;
use App\Models\Prototype;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Builds one prototype page on the queue. tries = 1: a generation costs a model call. */
class BuildPrototype implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $prototypeId, public string $kind = 'site') {}

    public function handle(PrototypeWriter $writer, DesignRefs $refs): void
    {
        $proto = Prototype::find($this->prototypeId);
        if (! $proto || $proto->status === 'ready') {
            return;
        }

        $proto->update(['status' => 'building', 'error' => null]);

        try {
            $out = $writer->build((string) $proto->prompt, $this->kind, $refs);
            $proto->update(['status' => 'ready', 'title' => $out['title'], 'html' => $out['html']]);
        } catch (Throwable $e) {
            Log::error('build prototype failed', ['id' => $proto->id, 'error' => $e->getMessage()]);
            $proto->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
        }
    }
    /** A worker timeout throws MaxAttemptsExceeded OUTSIDE handle(), so record it here or the row
        stays stuck in its in-progress state forever. */
    public function failed(\Throwable $e): void
    {
        \App\Models\Prototype::whereKey($this->prototypeId)->where('status', '!=', 'ready')
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
    }
}
