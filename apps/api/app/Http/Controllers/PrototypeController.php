<?php

namespace App\Http\Controllers;

use App\Jobs\BuildPrototype;
use App\Models\Prototype;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public, anonymous prompt-to-prototype. No sign-in: it is a lead magnet.
 *
 * Two things keep the free tier from being a bill. A per-IP daily cap on top of route throttling,
 * and a short life on every prototype. The generated HTML is untrusted, so raw() serves it with a
 * CSP that forbids every external request and lets only our own share page frame it; the page is
 * also shown inside a sandboxed iframe, so this is defence in depth, not the only wall.
 */
class PrototypeController extends Controller
{
    private const PER_IP_PER_DAY = 5;

    private const LIVE_DAYS = 7;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['prompt' => 'required|string|min:12|max:1200']);
        $ip = (string) $request->ip();

        $today = Prototype::where('ip', $ip)->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= self::PER_IP_PER_DAY) {
            return response()->json(['error' => 'Bạn đã tạo tối đa số prototype miễn phí hôm nay. Thử lại ngày mai hoặc liên hệ chúng tôi.'], 429);
        }

        $proto = Prototype::create([
            'status' => 'queued',
            'prompt' => $data['prompt'],
            'ip' => $ip,
            'expires_at' => now()->addDays(self::LIVE_DAYS),
        ]);

        BuildPrototype::dispatch($proto->id);

        return response()->json(['id' => $proto->id, 'status' => 'queued'], 202);
    }

    /** Status the share page polls while building, plus what it needs to render once ready. */
    public function show(Prototype $prototype): JsonResponse
    {
        $expired = $prototype->expires_at->isPast();

        return response()->json([
            'id' => $prototype->id,
            'status' => $expired ? 'expired' : $prototype->status,
            'title' => $prototype->title,
            'error' => $prototype->error,
            'expires_at' => $prototype->expires_at->toIso8601String(),
        ]);
    }

    /** The generated page itself. Untrusted content, locked down by CSP; framed by the share page. */
    public function raw(Prototype $prototype): Response
    {
        abort_unless($prototype->isLive(), 410, 'Prototype đã hết hạn hoặc chưa sẵn sàng.');

        $csp = implode('; ', [
            "default-src 'none'",
            "style-src 'unsafe-inline'",
            "script-src 'unsafe-inline'",
            "img-src data:",
            "font-src data:",
            "base-uri 'none'",
            "form-action 'none'",
            // Only our own share page may frame it; nobody can embed it elsewhere.
            "frame-ancestors 'self' https://appwerk.codemenschen.at",
        ]);

        return response((string) $prototype->html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
