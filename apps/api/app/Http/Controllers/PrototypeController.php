<?php

namespace App\Http\Controllers;

use App\Domain\Ai\PrototypeQuestions;
use App\Domain\Ai\PrototypeWriter;
use App\Domain\Analytics\Analytics;
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

    /**
     * How much a visitor may write. The first ceiling was 1200 characters and it was hit by
     * exactly the people the study stage was built for: a bakery owner pasting the whole
     * story of the shop. A long brief makes a better prototype, not a slower one; 4000
     * characters is about a page and well inside what the model reads in one go.
     */
    public const MAX_PROMPT = 4000;

    /** How many pictures a visitor may upload with the sentence, and how big each may be (KB). */
    public const MAX_UPLOADS = 4;

    private const MAX_UPLOAD_KB = 8192;

    /**
     * The few questions asked before the build (PrototypeQuestions). The same sign-in gate as
     * store(): a visitor who would be stopped there is stopped here, before answering anything.
     */
    public function questions(Request $request, PrototypeQuestions $questions): JsonResponse
    {
        $data = $request->validate([
            'prompt' => 'required|string|min:12|max:'.self::MAX_PROMPT,
            'kind' => 'nullable|in:'.implode(',', PrototypeWriter::KINDS),
            'locale' => 'nullable|in:de,en',
        ]);
        $kind = $data['kind'] ?? 'site';
        if (($gate = $this->gate($request, $kind)) !== null) {
            return $gate;
        }

        return response()->json(['questions' => $questions->ask($data['prompt'], $kind, $data['locale'] ?? 'de')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => 'required|string|min:12|max:'.self::MAX_PROMPT,
            // What to draw: the app itself, the ads for it, or a landing page.
            'kind' => 'nullable|in:'.implode(',', PrototypeWriter::KINDS),
            // The answers to the questions, one "question answer" per line.
            'details' => 'nullable|string|max:2000',
            // The business's own pictures: product, shop, logo.
            'images' => 'nullable|array|max:'.self::MAX_UPLOADS,
            'images.*' => 'file|mimes:jpeg,png,webp|max:'.self::MAX_UPLOAD_KB,
        ]);
        $ip = (string) $request->ip();
        $kind = $data['kind'] ?? 'site';
        if (($gate = $this->gate($request, $kind)) !== null) {
            return $gate;
        }

        // The cap is there to stop an anonymous visitor spending our money on generations. An
        // operator testing the funnel is not that, and must not eat the public allowance either,
        // so a signed-in admin passes straight through. The route stays open to everyone else:
        // a token is read if one is sent, never required.
        if (! ($request->user('sanctum')?->isAdmin() ?? false)) {
            $today = Prototype::where('ip', $ip)->where('created_at', '>=', now()->startOfDay())->count();
            if ($today >= self::PER_IP_PER_DAY) {
                return response()->json(['error' => 'Daily limit of free prototypes reached for this address. Come back tomorrow or get in touch.'], 429);
            }
        }

        // The answers travel with the sentence: the builder reads them as part of the brief, and
        // "turn it into a real project" carries them into the wizard as well.
        $prompt = trim($data['prompt']);
        if (trim($data['details'] ?? '') !== '') {
            $prompt .= "\n\n".trim($data['details']);
        }

        $proto = Prototype::create([
            'status' => 'queued',
            'kind' => $kind,
            'prompt' => $prompt,
            'ip' => $ip,
            'expires_at' => now()->addDays(self::LIVE_DAYS),
        ]);

        // Kept beside the other customer uploads, where the queue can read them, and removed
        // with the prototype when it expires.
        $files = $request->file('images') ?? [];
        if ($files !== []) {
            $dir = self::uploadDir($proto->id);
            @mkdir($dir, 0775, true);
            $uploads = [];
            foreach (array_values($files) as $n => $file) {
                $path = $dir.'/'.($n + 1).'.'.$file->extension();
                $file->move($dir, basename($path));
                $name = preg_replace('~[^\w.\- ]+~u', '', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'picture';
                $uploads[] = ['path' => $path, 'name' => mb_substr($name, 0, 60)];
            }
            $proto->update(['uploads' => $uploads]);
        }

        BuildPrototype::dispatch($proto->id, $kind);
        app(Analytics::class)->record('prototype_requested', $request, [], ['kind' => $kind, 'prototype' => $proto->id,
            'uploads' => count($files), 'details' => trim($data['details'] ?? '') !== '']);

        return response()->json(['id' => $proto->id, 'status' => 'queued'], 202);
    }

    public static function uploadDir(string $id): string
    {
        return rtrim((string) config('services.media.uploads_path'), '/').'/prototypes/'.$id;
    }

    /**
     * The first prototype is free and needs nothing. Ads, which spend two renders on the image
     * agent, and every prototype after the first from the same address, ask for an e-mail first
     * (owner's decision 2026-09-18): changing IP no longer buys unlimited generations, and each
     * build after the first comes with a way to reach the visitor.
     */
    private function gate(Request $request, string $kind): ?JsonResponse
    {
        if ($request->user('sanctum') !== null) {
            return null;
        }
        $before = Prototype::where('ip', (string) $request->ip())->where('created_at', '>=', now()->subDays(self::LIVE_DAYS))->exists();
        if ($kind !== 'ads' && ! $before) {
            return null;
        }

        return response()->json(['error' => 'Sign in with your e-mail to build this prototype.',
            'code' => 'sign_in', 'reason' => $kind === 'ads' ? 'ads' : 'again'], 401);
    }

    /** Status the share page polls while building, plus what it needs to render once ready. */
    public function show(Prototype $prototype): JsonResponse
    {
        $expired = $prototype->expires_at->isPast();

        return response()->json([
            'id' => $prototype->id,
            'status' => $expired ? 'expired' : $prototype->status,
            // Which step a build is on, so the wait says what is happening rather than "moment".
            'stage' => $prototype->stage,
            // When it was asked for, so the wait can show a clock instead of feeling endless.
            'created_at' => $prototype->created_at->toIso8601String(),
            // The share page frames an app in a phone and a site in a window.
            'kind' => $prototype->kind,
            // An ad built by Codex alone has two steps, not five, and takes a minute or two: the
            // wait says so instead of promising four to six minutes of steps that never come.
            'mode' => $prototype->kind === 'ads'
                ? ($prototype->qa['mode'] ?? ($prototype->status === 'ready' ? 'hybrid' : PrototypeWriter::adsMode()))
                : null,
            'title' => $prototype->title,
            // The visitor's own sentence, so "turn it into a real app" can carry it into the
            // wizard instead of asking them to type the same thing twice.
            'prompt' => $prototype->prompt,
            'error' => $prototype->error,
            // Only what the share page has to print: the photographer and where the photo is from.
            'photo_credit' => $prototype->qa['photo_credit'] ?? null,
            'photo_credit_url' => $prototype->qa['photo_credit_url'] ?? null,
            'expires_at' => $prototype->expires_at->toIso8601String(),
        ]);
    }

    /** The generated page itself. Untrusted content, locked down by CSP; framed by the share page. */
    public function raw(Prototype $prototype): Response
    {
        abort_unless($prototype->isLive(), 410, 'This prototype has expired or is not ready yet.');

        $csp = implode('; ', [
            "default-src 'none'",
            "style-src 'unsafe-inline'",
            "script-src 'unsafe-inline'",
            'img-src data:',
            'font-src data:',
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
