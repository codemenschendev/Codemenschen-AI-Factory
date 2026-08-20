<?php

namespace App\Http\Controllers;

use App\Models\PipelineRun;
use App\Services\PipelineOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Worker callbacks. Auth: the per-run callback token (single purpose). */
class InternalRunController extends Controller
{
    public function heartbeat(Request $request, PipelineRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);
        $run->update(['heartbeat_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function complete(Request $request, PipelineRun $run, PipelineOrchestrator $orchestrator): JsonResponse
    {
        $this->authorizeRun($request, $run);
        abort_if($run->status !== 'running', 409, 'Run is not running');

        $data = $request->validate([
            'status' => 'required|in:succeeded,failed',
            'output' => 'array',
            'error' => 'nullable|string',
            'tokens_in' => 'nullable|integer|min:0',
            'tokens_out' => 'nullable|integer|min:0',
        ]);

        $run->update([
            'status' => $data['status'],
            'output' => $data['output'] ?? [],
            'error' => $data['error'] ?? null,
            'tokens_in' => $data['tokens_in'] ?? 0,
            'tokens_out' => $data['tokens_out'] ?? 0,
            'finished_at' => now(),
        ]);

        $data['status'] === 'succeeded'
            ? $orchestrator->onStageCompleted($run)
            : $orchestrator->onStageFailed($run);

        return response()->json(['ok' => true]);
    }

    private function authorizeRun(Request $request, PipelineRun $run): void
    {
        abort_unless(
            hash_equals($run->callback_token, (string) $request->bearerToken()),
            403,
            'Invalid run token',
        );
    }
}
