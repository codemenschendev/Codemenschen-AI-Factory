<?php

namespace App\Http\Controllers;

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
                'bytes' => (int) $v->bytes,
                'duration_seconds' => $v->duration_seconds,
                'created_at' => $v->created_at->toIso8601String(),
                'project' => ['id' => $v->project->id, 'name' => $v->project->name],
            ]);

        return response()->json(['videos' => $videos]);
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
