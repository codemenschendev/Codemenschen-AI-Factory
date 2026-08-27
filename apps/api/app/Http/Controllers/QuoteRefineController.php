<?php

namespace App\Http\Controllers;

use App\Services\Refiner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Sharpen my idea" in the storefront wizard — see Refiner for the limits. */
class QuoteRefineController extends Controller
{
    public function __invoke(Request $request, Refiner $refiner): JsonResponse
    {
        $data = $request->validate([
            'text' => 'required|string|min:30|max:800',
            'locale' => 'nullable|in:de,en',
            'answers' => 'array|max:6',
            'answers.*' => 'string|max:200',
        ]);
        $res = $refiner->run([
            'mode' => 'idea',
            'text' => $data['text'],
            'locale' => $data['locale'] ?? 'de',
            'answers' => array_values($data['answers'] ?? []),
        ], $request->user('sanctum'), (string) $request->ip());

        return response()->json($res['body'], $res['status']);
    }
}
