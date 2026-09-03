<?php

namespace App\Http\Controllers;

use App\Domain\Ads\AdFormats;
use App\Domain\Ai\AdScriptWriter;
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

    /**
     * The canvases on offer, so the picker is built from the table instead of repeating it.
     * Public: these are published platform specs, and the list gives nothing away.
     *
     * Unready formats are left out. They are real sizes with a correct entry, but the text layer
     * still lays out for a large canvas, and offering them would produce ads nobody can read.
     */
    public function formats(): JsonResponse
    {
        $out = [];
        foreach (['video', 'image'] as $kind) {
            foreach (AdFormats::forKind($kind) as $key => $f) {
                $out[$kind][] = [
                    'key' => $key,
                    'label' => $f['label'],
                    'group' => $f['group'],
                    'size' => $f['w'].'x'.$f['h'],
                ];
            }
        }

        return response()->json($out);
    }

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

        // The goal list travels with the ads so the portal never keeps its own copy of it: a goal
        // added in AdScriptWriter::GOALS shows up here, and the portal only needs a label for it.
        return response()->json([
            'ads' => $ads,
            'goals' => array_keys(AdScriptWriter::GOALS),
            'angles' => array_keys(AdScriptWriter::ANGLES),
        ]);
    }

    /** Prompt in, queued ad out. The render itself happens on the queue (RenderProjectAd). */
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            'prompt' => 'required|string|min:10|max:2000',
            'kind' => 'nullable|in:video,image',
            // Every canvas in the table, checked against the chosen kind just below: the rule
            // cannot see `kind`, so it lets any format through and the pairing is checked after.
            'format' => 'nullable|'.AdFormats::rule(),
            'language' => 'nullable|in:de,en',
            // background: auto = screenshot the page if the brief names one, else an AI photo;
            // site = insist on the screenshot; photo = always an AI photo, never the screenshot.
            'background' => 'nullable|in:auto,site,photo',
            // What the ad has to make the reader do. Empty means the copy closes on whatever
            // action the subject itself offers.
            'goal' => 'nullable|in:'.implode(',', array_keys(AdScriptWriter::GOALS)),
            // How the story is told. Empty leaves the shape to the copywriter.
            'angle' => 'nullable|in:'.implode(',', array_keys(AdScriptWriter::ANGLES)),
        ]);

        // One render at a time per project: pictures are paid for per scene, and a customer
        // hammering the button would otherwise queue a bill.
        $busy = $project->ads()->whereIn('status', ['queued', 'rendering'])->exists();
        abort_if($busy, 409, 'Đang dựng một quảng cáo cho project này, đợi xong đã.');

        $kind = $data['kind'] ?? 'video';
        $format = $data['format'] ?? AdFormats::DEFAULT;
        $spec = AdFormats::get($format);
        // A film has nowhere to run on a 320x50 banner, and the display units still lay their text
        // out for a large canvas. Say which it is rather than rendering something unusable.
        abort_unless($spec && in_array($kind, $spec['kinds'], true), 422,
            'Khổ này không dùng được cho loại quảng cáo đã chọn.');
        abort_unless($spec['ready'], 422,
            'Khổ này đã có kích thước đúng nhưng phần chữ chưa dựng cho canvas nhỏ.');
        $size = AdFormats::size($format);

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
                'goal' => $data['goal'] ?? null,
                'angle' => $data['angle'] ?? null,
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
