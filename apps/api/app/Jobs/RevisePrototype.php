<?php

namespace App\Jobs;

use App\Domain\Ai\PrototypePhoto;
use App\Domain\Ai\PrototypeWriter;
use App\Models\Prototype;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one change a signed-in visitor asked for. The page they saw stays live until the changed
 * one is ready, and a change that fails gives the allowance back: the visitor lost nothing.
 */
class RevisePrototype implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $prototypeId, public string $change) {}

    public function handle(PrototypeWriter $writer, PrototypePhoto $photo): void
    {
        $proto = Prototype::find($this->prototypeId);
        if (! $proto || $proto->html === null) {
            return;
        }
        try {
            $out = $writer->revise((string) $proto->html, (string) $proto->kind, $proto->qa ?? [], (string) $proto->prompt, $this->change, $photo);
            $proto->update(['status' => 'ready', 'stage' => null, 'html' => $out['html'], 'title' => $out['title'] ?: $proto->title,
                'qa' => $out['qa'], 'error' => null]);
        } catch (Throwable $e) {
            $this->undo($e);
        }
    }

    public function failed(Throwable $e): void
    {
        $this->undo($e);
    }

    private function undo(Throwable $e): void
    {
        Log::error('revise prototype failed', ['id' => $this->prototypeId, 'error' => $e->getMessage()]);
        $proto = Prototype::find($this->prototypeId);
        if ($proto === null) {
            return;
        }
        $qa = $proto->qa ?? [];
        $qa['revision_failed'] = mb_substr($e->getMessage(), 0, 300);
        $proto->update(['status' => 'ready', 'stage' => null, 'revisions' => max(0, $proto->revisions - 1), 'qa' => $qa]);
    }
}
