<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the static web export the release stage puts in
 * <artifacts>/<project>/web/ — the portal's "open preview" link. Reviewers
 * need nothing installed; the URL is unguessable (UUID v7) and unindexed.
 */
class PreviewController extends Controller
{
    private const MIME = [
        'html' => 'text/html; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'mjs' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'json' => 'application/json', 'map' => 'application/json', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'wasm' => 'application/wasm', 'txt' => 'text/plain; charset=utf-8',
    ];

    public function __invoke(Project $project, string $path = ''): BinaryFileResponse
    {
        $base = realpath(rtrim(config('services.worker.artifacts_path'), '/')."/{$project->id}/web");
        abort_unless($base, 404, 'No web preview for this project');

        $rel = trim($path, '/');
        abort_if(str_contains($rel, '..'), 404);
        $file = $rel === '' ? realpath("$base/index.html") : realpath("$base/$rel");
        if ($file && is_dir($file)) {
            $file = realpath("$file/index.html");
        }
        if (! $file || ! is_file($file) || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR)) {
            // Client-side routes fall back to the app shell; missing assets do not.
            abort_if((bool) preg_match('/\.[a-z0-9]{1,5}$/i', $rel), 404);
            $file = realpath("$base/index.html");
            abort_unless($file && is_file($file), 404);
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return response()->file($file, [
            'Content-Type' => self::MIME[$ext] ?? 'application/octet-stream',
            'Cache-Control' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
