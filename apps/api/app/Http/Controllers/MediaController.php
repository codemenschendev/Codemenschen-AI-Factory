<?php

namespace App\Http\Controllers;

use App\Jobs\RenderProjectVideo;
use App\Models\Project;
use App\Models\ProjectVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Marketing clips, scoped to the customer's own projects.
 *
 * Files are rendered by ops/make-video.py and registered with `php artisan video:register`;
 * project_videos is what ties a file to a project. Ownership is checked the same way as
 * MeController::downloadBuild — the customer id on the project, not on the video.
 */
class MediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $videos = ProjectVideo::query()
            ->whereIn('project_id', $request->user()->projects()->select('id'))
            ->with('project:id,name')
            ->latest()
            ->get()
            ->map(fn (ProjectVideo $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'status' => $v->status,
                'error' => $v->error,
                'bytes' => (int) $v->bytes,
                'duration_seconds' => $v->duration_seconds,
                'created_at' => $v->created_at->toIso8601String(),
                'project' => ['id' => $v->project->id, 'name' => $v->project->name],
            ]);

        return response()->json(['videos' => $videos]);
    }

    /** Prompt in, queued clip out. The render itself happens on the queue (RenderProjectVideo). */
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            'prompt' => 'required|string|min:10|max:2000',
            'format' => 'nullable|in:vertical,square,landscape',
            'language' => 'nullable|in:de,en',
        ]);

        // One render at a time per project: images are paid for per scene, and a customer
        // hammering the button would otherwise queue a bill.
        $busy = $project->videos()->whereIn('status', ['queued', 'rendering'])->exists();
        abort_if($busy, 409, 'Đang dựng một video cho project này, đợi xong đã.');

        $size = match ($data['format'] ?? 'vertical') {
            'square' => '1080x1080',
            'landscape' => '1920x1080',
            default => '1080x1920',
        };

        $video = $project->videos()->create([
            'name' => mb_substr(trim($data['prompt']), 0, 60),
            'status' => 'queued',
            'source' => 'ai',
            'prompt' => $data['prompt'],
            'spec' => ['size' => $size, 'language' => $data['language'] ?? 'de'],
        ]);

        RenderProjectVideo::dispatch($video->id);

        return response()->json(['id' => $video->id, 'status' => $video->status], 202);
    }

    public function download(Request $request, ProjectVideo $video): BinaryFileResponse
    {
        abort_unless($video->project->customer_id === $request->user()->id, 404);

        // Resolve before trusting: `path` comes from the register command, and a symlink placed
        // in the media directory could otherwise point anywhere on the box.
        $real = realpath($video->absolutePath());
        $base = realpath(rtrim((string) config('services.media.videos_path'), '/'));
        abort_unless($real && $base && str_starts_with($real, $base.'/') && is_file($real), 404);

        return response()->file($real, ['Content-Type' => 'video/mp4']);
    }
}
