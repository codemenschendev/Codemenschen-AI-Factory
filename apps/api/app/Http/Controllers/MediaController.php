<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Marketing clips rendered by ops/make-video.py and dropped into services.media.videos_path.
 *
 * There is no videos table yet: the directory listing IS the index, which keeps the first
 * version free of a migration. Every clip is visible to every signed-in customer, on purpose
 * for now — the page behind this is a hidden preview that will be made public later.
 */
class MediaController extends Controller
{
    /** Only these characters may appear in an id, so a name can never climb out of the base dir. */
    private const ID = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}$/';

    private function baseDir(): string
    {
        return rtrim((string) config('services.media.videos_path'), '/');
    }

    public function index(Request $request): JsonResponse
    {
        $base = $this->baseDir();
        $rows = [];

        foreach (glob($base.'/*.mp4') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $rows[] = [
                'id' => pathinfo($path, PATHINFO_FILENAME),
                'name' => basename($path),
                'bytes' => filesize($path) ?: 0,
                'created_at' => date('c', filemtime($path) ?: time()),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return response()->json(['videos' => $rows]);
    }

    public function download(Request $request, string $id): BinaryFileResponse
    {
        abort_unless(preg_match(self::ID, $id) === 1, 404);

        $base = $this->baseDir();
        $path = $base.'/'.$id.'.mp4';

        // Resolve before trusting: a symlink inside the directory could still point elsewhere.
        $real = realpath($path);
        $realBase = realpath($base);
        abort_unless($real && $realBase && str_starts_with($real, $realBase.'/'), 404);
        abort_unless(is_file($real), 404);

        return response()->file($real, ['Content-Type' => 'video/mp4']);
    }
}
