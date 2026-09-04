<?php

namespace App\Http\Controllers;

use App\Domain\Design\DesignLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Browsing the reference library from the admin panel.
 *
 * Read-only, deliberately: the catalog is written by the labelling script on the host, and two
 * writers to one JSON file is a race waiting to happen. Nothing here changes the library, so the
 * worst a mistake on this screen can do is show the wrong picture.
 *
 * Same split as the photo library: the listing sits behind auth, the image itself carries a signed
 * URL because an <img> tag sends no Authorization header.
 */
class DesignLibraryController extends Controller
{
    public function index(Request $request, DesignLibrary $library): JsonResponse
    {
        if (! $library->available()) {
            return response()->json(['available' => false, 'total' => 0, 'labelled' => 0, 'facets' => [], 'items' => []]);
        }

        $filters = $request->validate([
            'medium' => 'sometimes|string|max:20',
            'screen_type' => 'sometimes|string|max:40',
            'industry' => 'sometimes|string|max:40',
            'pattern' => 'sometimes|string|max:40',
            'category' => 'sometimes|string|max:40',
            'grade' => 'sometimes|string|max:40',
            'scheme' => 'sometimes|in:light,dark',
            'labelled' => 'sometimes|in:yes,no',
            'q' => 'sometimes|string|max:120',
            'page' => 'sometimes|integer|min:1',
        ]);

        $page = $library->search($filters, (int) ($filters['page'] ?? 1));
        $facets = $library->facets();

        return response()->json([
            'available' => true,
            'total' => $facets['total'],
            'labelled' => $facets['labelled'],
            'facets' => $facets['facets'],
            'matched' => $page['total'],
            'page' => $page['page'],
            'pages' => $page['pages'],
            'items' => array_map(fn (array $r) => $r + [
                'url' => URL::temporarySignedRoute('admin.design-library.image', now()->addHour(), ['id' => $r['id']]),
            ], $page['items']),
        ]);
    }

    /** Signed, no session. The id is inside the signature, so it cannot be swapped for another. */
    public function image(string $id, DesignLibrary $library): BinaryFileResponse
    {
        $path = $library->path($id);
        abort_unless($path, 404);

        $mime = str_ends_with($path, '.png') ? 'image/png'
            : (str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg');

        return response()->file($path, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=3600']);
    }
}
