<?php

namespace App\Http\Controllers;

use App\Domain\Pricing\RoughEstimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The live price beside the prototype form, see RoughEstimate. */
class RoughEstimateController extends Controller
{
    public function __invoke(Request $request, RoughEstimate $estimate): JsonResponse
    {
        $data = $request->validate([
            'text' => 'required|string|min:20|max:800',
            'locale' => 'nullable|in:de,en',
        ]);
        $res = $estimate->run($data['text'], $data['locale'] ?? 'de', (string) $request->ip());

        return response()->json($res['body'], $res['status']);
    }
}
