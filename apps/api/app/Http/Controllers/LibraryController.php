<?php

namespace App\Http\Controllers;

use App\Domain\Library\ImageLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The operator's view of the photo library.
 *
 * Everything here except the image itself sits behind `auth:sanctum` + `admin`. The image cannot:
 * a browser asks for <img src> without an Authorization header, so each row carries a signed URL
 * that stands on its own for an hour. The signature is the credential, which is why that one route
 * is deliberately outside the admin group rather than accidentally outside it.
 */
class LibraryController extends Controller
{
    public function index(Request $request, ImageLibrary $library): JsonResponse
    {
        $rows = $library->all($request->query('q') ? (string) $request->query('q') : null);

        return response()->json([
            'stats' => $library->stats(),
            'items' => array_map(fn (array $r) => [
                'id' => $r['id'],
                'caption' => $r['caption'],
                'project' => $r['project'],
                'shared' => $r['shared'],
                'bytes' => $r['bytes'],
                'url' => URL::temporarySignedRoute('admin.library.image', now()->addHour(), ['id' => $r['id']]),
            ], $rows),
        ]);
    }

    /** The kill switch. Off means renders stop looking and go straight to generating. */
    public function state(Request $request, ImageLibrary $library): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $library->setEnabled((bool) $data['enabled']);

        return response()->json($library->stats());
    }

    public function update(Request $request, string $id, ImageLibrary $library): JsonResponse
    {
        $data = $request->validate([
            'shared' => 'sometimes|boolean',
            'caption' => 'sometimes|string|max:600',
        ]);

        $ok = false;
        if (array_key_exists('shared', $data)) {
            $ok = $library->setShared($id, (bool) $data['shared']);
        }
        if (array_key_exists('caption', $data)) {
            $ok = $library->setCaption($id, (string) $data['caption']) || $ok;
        }
        abort_unless($ok, 404);

        return response()->json(['ok' => true]);
    }

    public function destroy(string $id, ImageLibrary $library): JsonResponse
    {
        abort_unless($library->remove($id), 404);

        return response()->json(['ok' => true]);
    }

    /** Signed, no session. The id comes from the signature, so it cannot be swapped for another. */
    public function image(string $id, ImageLibrary $library): BinaryFileResponse
    {
        $path = $library->path($id);
        abort_unless($path, 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }
}
