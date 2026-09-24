<?php

namespace App\Http\Controllers;

use App\Domain\Ai\ImageService;
use App\Domain\Ai\OpenAiImageKey;
use App\Services\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The OpenAI key a paying customer's renders are billed to (OpenAiImageKey). Write-only: the
 * panel can set, replace or remove it, and reads back nothing but "set, ends in …abcd".
 */
class AdminImageKeyController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['key' => OpenAiImageKey::status()]);
    }

    /** OpenAI is asked first, so a typo is refused here and not found in a customer's failed ad. */
    public function store(Request $request, ImageService $images, Notify $notify): JsonResponse
    {
        $key = trim((string) $request->validate(['api_key' => 'required|string|min:20|max:300'])['api_key']);
        abort_unless(str_starts_with($key, 'sk-'), 422, 'An OpenAI API key starts with "sk-".');

        $check = $images->checkKey($key);
        abort_unless($check['ok'], 422, (string) $check['detail']);

        $by = (string) $request->user()->email;
        OpenAiImageKey::put($key, $by);
        $notify->system("OpenAI key for paid renders set by {$by}");

        return response()->json(['key' => OpenAiImageKey::status()]);
    }

    public function destroy(Request $request, Notify $notify): JsonResponse
    {
        $by = (string) $request->user()->email;
        OpenAiImageKey::clear($by);
        $notify->system("OpenAI key for paid renders removed by {$by}, paid renders use the sidecar again");

        return response()->json(['key' => OpenAiImageKey::status()]);
    }
}
