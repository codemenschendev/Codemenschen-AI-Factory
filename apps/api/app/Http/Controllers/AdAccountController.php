<?php

namespace App\Http\Controllers;

use App\Domain\Ads\AccountLink;
use App\Models\AdAccountLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The customer's own ad accounts, in the settings of their account (2026-09-23).
 *
 * Three fields on screen and one button. Everything else happens on the platform, where the
 * customer presses accept once and can cut the link again whenever they like. We keep no
 * credential of theirs and a link by itself spends nothing.
 */
class AdAccountController extends Controller
{
    public function index(Request $request, AccountLink $links): JsonResponse
    {
        return response()->json([
            'ours' => $links->ours(),
            'accounts' => $this->rows($request),
        ]);
    }

    /** Sends the link request, then reports what the platform says about it right away. */
    public function store(Request $request, AccountLink $links): JsonResponse
    {
        $data = $request->validate([
            'platform' => 'required|in:meta,google',
            'external_id' => 'required|string|max:40',
            // Meta only: the page the ad is published by. Google has no equivalent.
            'page_id' => 'nullable|string|max:40',
        ]);

        try {
            $link = $links->request($request->user(), $data['platform'], $data['external_id'], $data['page_id'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['account' => self::row($link)], 201);
    }

    /** "I pressed accept, look again." */
    public function refresh(Request $request, AdAccountLink $adAccount, AccountLink $links): JsonResponse
    {
        $this->own($request, $adAccount);

        return response()->json(['account' => self::row($links->refresh($adAccount))]);
    }

    /**
     * Forgets the account here. The link on the platform is the customer's own to cut, in their
     * own account, and we say so rather than pretending this button reaches that far.
     */
    public function destroy(Request $request, AdAccountLink $adAccount): JsonResponse
    {
        $this->own($request, $adAccount);
        $adAccount->delete();

        return response()->json(['accounts' => $this->rows($request)]);
    }

    private function own(Request $request, AdAccountLink $link): void
    {
        abort_unless($link->customer_id === $request->user()->id, 404);
    }

    /** @return list<array<string,mixed>> */
    private function rows(Request $request): array
    {
        return AdAccountLink::where('customer_id', $request->user()->id)->orderBy('platform')->get()
            ->map(fn (AdAccountLink $l) => self::row($l))->all();
    }

    /** @return array<string,mixed> */
    private static function row(AdAccountLink $l): array
    {
        return [
            'id' => $l->id,
            'platform' => $l->platform,
            'external_id' => $l->platform === 'google' ? AccountLink::dashed($l->external_id) : $l->external_id,
            'page_id' => $l->page_id,
            'page_name' => $l->page_name,
            'status' => $l->status,
            'name' => $l->name,
            'checked_at' => $l->checked_at?->toIso8601String(),
            'error' => $l->error,
        ];
    }
}
