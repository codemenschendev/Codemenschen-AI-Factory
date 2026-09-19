<?php

namespace App\Http\Controllers;

use App\Domain\Ai\Campaign;
use App\Domain\Ai\PrototypeQuestions;
use App\Domain\Ai\PrototypeWriter;
use App\Domain\Analytics\Analytics;
use App\Jobs\BuildPrototype;
use App\Jobs\RevisePrototype;
use App\Models\Prototype;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public prompt-to-prototype, the lead magnet. Every build needs an e-mail: a visitor who is not
 * signed in gets a link that signs them in and starts the build (owner's decision 2026-09-19).
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
            // A visitor who is not signed in gives an e-mail; the build starts from the link.
            'email' => 'nullable|email|max:190',
            'locale' => 'nullable|in:de,en',
        ]);
        $ip = (string) $request->ip();
        $kind = $data['kind'] ?? 'site';
        $user = $request->user('sanctum');
        if ($user === null && empty($data['email'])) {
            return response()->json(['error' => 'Give your e-mail: the build starts when you open the link we send.',
                'code' => 'email'], 422);
        }

        // The cap is there to stop an anonymous visitor spending our money on generations. An
        // operator testing the funnel is not that, and must not eat the public allowance either,
        // so a signed-in admin passes straight through. The route stays open to everyone else:
        // a token is read if one is sent, never required.
        if (! ($request->user('sanctum')?->isAdmin() ?? false)) {
            // The parts of a campaign are not counted: the visitor asked for one thing.
            $today = Prototype::where('ip', $ip)->whereNull('parent_id')->where('created_at', '>=', now()->startOfDay())->count();
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

        // Every build needs an address now (owner's decision 2026-09-19). A signed-in visitor's
        // starts at once; anybody else's waits for the link in the e-mail, which signs them in and
        // starts it. Nothing is spent on an address that is never opened.
        $proto = Prototype::create([
            'status' => $user === null ? 'waiting' : 'queued',
            'kind' => $kind,
            'prompt' => $prompt,
            'ip' => $ip,
            'customer_id' => $user?->id,
            'qa' => $user === null ? ['pending_email' => strtolower($data['email'])] : null,
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

        app(Analytics::class)->record('prototype_requested', $request, [], ['kind' => $kind, 'prototype' => $proto->id,
            'uploads' => count($files), 'details' => trim($data['details'] ?? '') !== '', 'waiting' => $user === null]);
        if ($user === null) {
            self::sendBuildLink($proto, strtolower($data['email']), $data['locale'] ?? 'de');

            return response()->json(['id' => $proto->id, 'status' => 'waiting'], 202);
        }
        BuildPrototype::dispatch($proto->id, $kind);

        return response()->json(['id' => $proto->id, 'status' => 'queued'], 202);
    }

    public static function uploadDir(string $id): string
    {
        return rtrim((string) config('services.media.uploads_path'), '/').'/prototypes/'.$id;
    }

    /** The e-mail whose link signs the visitor in and starts the build. Valid for a day. */
    private static function sendBuildLink(Prototype $proto, string $email, string $locale): void
    {
        $url = URL::temporarySignedRoute('prototypes.confirm', now()->addDay(), ['prototype' => $proto->id, 'locale' => $locale]);
        if (config('mail.default') === 'log') {
            Log::info('prototype.build_link', ['email' => $email, 'url' => $url]);

            return;
        }
        Mail::raw($locale === 'de'
            ? "Hallo,\n\nein Klick auf diesen Link bestätigt deine E-Mail und startet deinen Prototyp:\n\n$url\n\nDu landest direkt auf der Seite, auf der er entsteht. Der Link gilt 24 Stunden.\n\nWenn du nichts angefragt hast, ignoriere diese E-Mail.\n\nAppwerk"
            : "Hello,\n\none click on this link confirms your e-mail and starts your prototype:\n\n$url\n\nYou land right on the page where it is built. The link is valid for 24 hours.\n\nIf you did not ask for this, ignore this e-mail.\n\nAppwerk",
            fn ($m) => $m->to($email)->subject($locale === 'de' ? 'Starte deinen Appwerk Prototyp' : 'Start your Appwerk prototype'));
    }

    /**
     * The link from that e-mail: the address is confirmed, the customer exists from now on and is
     * signed in, and the build starts. Opened twice, it only signs in and shows the prototype.
     */
    public function confirm(Request $request, Prototype $prototype): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Link expired or invalid');
        $locale = in_array($request->query('locale'), ['de', 'en'], true) ? $request->query('locale') : 'de';
        $email = $prototype->qa['pending_email'] ?? null;
        $customer = $prototype->customer_id !== null ? Customer::find($prototype->customer_id)
            : ($email !== null ? Customer::firstOrCreate(['email' => $email], ['locale' => $locale]) : null);
        abort_if($customer === null, 410, 'This prototype can no longer be started.');

        if ($prototype->status === 'waiting') {
            $qa = $prototype->qa ?? [];
            unset($qa['pending_email']);
            $prototype->update(['status' => 'queued', 'customer_id' => $customer->id, 'qa' => $qa ?: null,
                'expires_at' => now()->addDays(self::LIVE_DAYS)]);
            BuildPrototype::dispatch($prototype->id, (string) $prototype->kind);
            app(Analytics::class)->record('prototype_confirmed', $request, ['customer_id' => $customer->id], ['kind' => $prototype->kind, 'prototype' => $prototype->id]);
        }
        $token = $customer->createToken('portal', ['portal'])->plainTextToken;
        $front = rtrim((string) config('services.frontend_url'), '/');

        return redirect()->away("$front/$locale/p/{$prototype->id}#token=$token");
    }

    /** How many changes a signed-in visitor may ask for on one free prototype. */
    public const REVISIONS = 1;

    /**
     * The one change (owner's decision 2026-09-19). Signed in only: the change is how the free
     * prototype turns into a conversation with somebody we can reach. The first change claims
     * the prototype for that customer; an admin is not counted.
     */
    public function revise(Request $request, Prototype $prototype): JsonResponse
    {
        $data = $request->validate(['change' => 'required|string|min:5|max:1000']);
        $user = $request->user();
        abort_unless($prototype->isLive(), 410, 'This prototype has expired or is not ready yet.');
        // A campaign has no page of its own; a change is asked for on one of its parts.
        abort_if($prototype->kind === 'campaign', 422, 'Change one part of the campaign.');
        $admin = $user->isAdmin();
        if (! $admin && $prototype->customer_id !== null && $prototype->customer_id !== $user->id) {
            return response()->json(['error' => 'This prototype belongs to another account.', 'code' => 'not_yours'], 403);
        }
        // The parts of a campaign share one allowance: one change for the campaign, not three.
        if (! $admin && Campaign::revisionsUsed($prototype) >= self::REVISIONS) {
            return response()->json(['error' => 'The free change for this prototype is used.', 'code' => 'used'], 403);
        }

        $qa = $prototype->qa ?? [];
        unset($qa['revision_failed']);
        $prototype->update(['status' => 'building', 'stage' => 'revising', 'revisions' => $prototype->revisions + 1,
            'customer_id' => $prototype->customer_id ?? $user->id, 'qa' => $qa]);
        RevisePrototype::dispatch($prototype->id, $data['change']);
        app(Analytics::class)->record('prototype_revised', $request, [], ['kind' => $prototype->kind, 'prototype' => $prototype->id]);

        return response()->json(['id' => $prototype->id, 'status' => 'building'], 202);
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
            // The one change: how many are left, and why the last one did not happen.
            'revisions_left' => max(0, self::REVISIONS - Campaign::revisionsUsed($prototype)),
            'revision_failed' => isset($prototype->qa['revision_failed']),
            // Only what the share page has to print: the photographer and where the photo is from.
            'photo_credit' => $prototype->qa['photo_credit'] ?? null,
            'photo_credit_url' => $prototype->qa['photo_credit_url'] ?? null,
            'expires_at' => $prototype->expires_at->toIso8601String(),
            // A campaign is shown as its parts: the ad, the landing page, the e-mails.
            'parts' => $prototype->kind === 'campaign' ? Prototype::where('parent_id', $prototype->id)->get()
                ->sortBy(fn (Prototype $p) => array_search($p->kind, Campaign::PARTS, true))->values()
                ->map(fn (Prototype $p) => ['id' => $p->id, 'kind' => $p->kind, 'status' => $expired ? 'expired' : $p->status,
                    'stage' => $p->stage, 'title' => $p->title, 'mode' => $p->kind === 'ads' ? ($p->qa['mode'] ?? null) : null,
                    'live_url' => $p->published_at !== null ? LandingController::url($p) : null])
                : null,
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
