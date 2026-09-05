<?php

namespace App\Jobs;

use App\Domain\Ai\PrototypePhoto;
use App\Domain\Ai\PrototypeWriter;
use App\Domain\Design\DesignLibrary;
use App\Domain\Design\DesignRefs;
use App\Domain\Qa\PageAudit;
use App\Models\Prototype;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Builds one prototype page on the queue. tries = 1: a generation costs a model call. */
class BuildPrototype implements ShouldQueue
{
    use Queueable;

    /**
     * Fifteen minutes, which is the sum and not a guess: the sidecar may take 300s to write the
     * page, the audit runs, a repair may take another 300s, the audit runs again, and photographs
     * are fetched. Ten minutes would cut a repaired page off at the last step.
     */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $prototypeId, public string $kind = 'site') {}

    public function handle(PrototypeWriter $writer, DesignRefs $refs, PageAudit $audit,
        DesignLibrary $library, PrototypePhoto $photo): void
    {
        $proto = Prototype::find($this->prototypeId);
        if (! $proto || $proto->status === 'ready') {
            return;
        }

        $proto->update(['status' => 'building', 'error' => null]);

        try {
            $out = $writer->build((string) $proto->prompt, $this->kind, $refs, $audit, $library, $photo);
            $proto->update([
                'status' => 'ready', 'title' => $out['title'], 'html' => $out['html'],
                'qa' => $out['qa'] ?? null,
            ]);

            // Not an error: the page is live either way. It is a line somebody can act on, and the
            // admin panel reads the same column to say which prototypes went out with a fault.
            if (($out['qa']['ok'] ?? null) === false) {
                Log::warning('prototype shipped with faults', [
                    'id' => $proto->id,
                    'faults' => array_column(PageAudit::blocking($out['qa']), 'check'),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('build prototype failed', ['id' => $proto->id, 'error' => $e->getMessage()]);
            $proto->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
        }
    }

    /** A worker timeout throws MaxAttemptsExceeded OUTSIDE handle(), so record it here or the row
        stays stuck in its in-progress state forever. */
    public function failed(Throwable $e): void
    {
        Prototype::whereKey($this->prototypeId)->where('status', '!=', 'ready')
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
    }
}
