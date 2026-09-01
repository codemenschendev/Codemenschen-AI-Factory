<?php

namespace App\Http\Controllers;

use App\Jobs\RenderProjectAd;
use App\Models\Project;
use App\Models\ProjectAd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ad creatives, scoped to the customer's own projects.
 *
 * Two kinds share this table and this pipeline, because they share everything that matters: the
 * prompt, the scene copy, the generated picture and the ownership rules. A video ad is several
 * scenes stitched by ffmpeg; an image ad is one scene rendered as a still.
 */
class MediaController extends Controller
{
    private const MIME = ['video' => 'video/mp4', 'image' => 'image/png'];

    public function index(Request $request): JsonResponse
    {
        $ads = ProjectAd::query()
            ->whereIn('project_id', $request->user()->projects()->select('id'))
            ->with('project:id,name')
            ->latest()
            ->get()
            ->map(fn (ProjectAd $a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'name' => $a->name,
                'status' => $a->status,
                'error' => $a->error,
                'bytes' => (int) $a->bytes,
                'created_at' => $a->created_at->toIso8601String(),
                'project' => ['id' => $a->project->id, 'name' => $a->project->name],
            ]);

        return response()->json(['ads' => $ads]);
    }

    /** Prompt in, queued ad out. The render itself happens on the queue (RenderProjectAd). */
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            'prompt' => 'required|string|min:10|max:2000',
            'kind' => 'nullable|in:video,image',
            'format' => 'nullable|in:vertical,square,landscape',
            'language' => 'nullable|in:de,en',
            // background: auto = screenshot the page if the brief names one, else an AI photo;
            // site = insist on the screenshot; photo = always an AI photo, never the screenshot.
            'background' => 'nullable|in:auto,site,photo',
        ]);

        // One render at a time per project: pictures are paid for per scene, and a customer
        // hammering the button would otherwise queue a bill.
        $busy = $project->ads()->whereIn('status', ['queued', 'rendering'])->exists();
        abort_if($busy, 409, 'Đang dựng một quảng cáo cho project này, đợi xong đã.');

        $size = match ($data['format'] ?? 'vertical') {
            'square' => '1080x1080',
            'landscape' => '1920x1080',
            default => '1080x1920',
        };

        $ad = $project->ads()->create([
            'kind' => $data['kind'] ?? 'video',
            'name' => mb_substr(trim($data['prompt']), 0, 60),
            'status' => 'queued',
            'source' => 'ai',
            'prompt' => $data['prompt'],
            'spec' => [
                'size' => $size,
                'language' => $data['language'] ?? 'de',
                'background' => $data['background'] ?? 'auto',
            ],
        ]);

        RenderProjectAd::dispatch($ad->id);

        return response()->json(['id' => $ad->id, 'status' => $ad->status], 202);
    }

    public function download(Request $request, ProjectAd $ad): BinaryFileResponse
    {
        abort_unless($ad->project->customer_id === $request->user()->id, 404);

        // Resolve before trusting: `path` comes from the render job or the register command, and
        // a symlink placed in the media directory could otherwise point anywhere on the box.
        $real = realpath($ad->absolutePath());
        $base = realpath(rtrim((string) config('services.media.videos_path'), '/'));
        abort_unless($real && $base && str_starts_with($real, $base.'/') && is_file($real), 404);

        return response()->file($real, ['Content-Type' => self::MIME[$ad->kind] ?? 'application/octet-stream']);
    }
}
