<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Analytics;
use App\Domain\Analytics\ValidationReport;
use App\Models\LandingSignup;
use App\Models\Project;
use App\Models\Prototype;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * The campaign's landing page, live (step 2 of the funnel, 2026-09-19). The page a campaign
 * built goes public at /l/{id} when its owner switches it on, and its sign-up form fills a
 * waitlist with double opt-in: an address is only counted once the link in the e-mail is opened.
 *
 * The page is the generated HTML as it is. The form is taken over here, by a script added when
 * the page is served, so every campaign built before this works too and the writer does not have
 * to get a fetch call right.
 */
class LandingController extends Controller
{
    /** How long a landing page stays up once switched on: a validation runs a week or two. */
    public const LIVE_DAYS = 30;

    /** Confirmation mails one page may send a day. A form is a way to make us send mail. */
    private const MAILS_PER_DAY = 300;

    /** Switches the campaign's landing page on. The campaign's owner or an admin. */
    public function publish(Request $request, Prototype $prototype): JsonResponse
    {
        $this->authorizeOwner($request, $prototype);
        abort_unless(self::isLanding($prototype) && $prototype->isLive(), 422, 'Only a finished campaign landing page can go live.');
        abort_if($prototype->parent_id === null, 422, 'A bought website is switched on by its payment.');

        $until = now()->addDays(self::LIVE_DAYS);
        $prototype->update(['published_at' => $prototype->published_at ?? now()]);
        // The campaign and its parts stay up with the page: the owner shows the ad and the e-mails
        // next to the numbers.
        Prototype::where(fn ($q) => $q->whereKey($prototype->parent_id)->orWhere('parent_id', $prototype->parent_id))
            ->where('expires_at', '<', $until)->update(['expires_at' => $until]);
        app(Analytics::class)->record('landing_published', $request, ['customer_id' => $request->user()->id], ['prototype' => $prototype->id]);

        return response()->json(['url' => self::url($prototype), 'published_at' => $prototype->published_at->toIso8601String()]);
    }

    /** The waitlist, for the owner: counts and the addresses. */
    public function signups(Request $request, Prototype $prototype): JsonResponse
    {
        $this->authorizeOwner($request, $prototype);
        $rows = LandingSignup::where('prototype_id', $prototype->id)->latest()->get();

        return response()->json([
            'url' => $prototype->published_at ? self::url($prototype) : null,
            'confirmed' => $rows->where('status', 'confirmed')->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'signups' => $rows->map(fn (LandingSignup $s) => ['email' => $s->email, 'status' => $s->status,
                'source' => $s->source, 'created_at' => $s->created_at->toIso8601String(),
                'confirmed_at' => $s->confirmed_at?->toIso8601String()])->values(),
        ]);
    }

    /** The validation report of a campaign, for its owner (or an admin). */
    public function report(Request $request, Prototype $prototype, ValidationReport $report): JsonResponse
    {
        $this->authorizeOwner($request, $prototype);
        abort_unless($prototype->kind === 'campaign', 422, 'Only a campaign has a report.');

        return response()->json($report->build($prototype));
    }

    /** The public page. */
    public function page(Request $request, Prototype $prototype): Response
    {
        abort_unless(self::isLanding($prototype) && $prototype->published_at !== null && $prototype->isLive(), 404);
        $lang = self::lang($prototype);
        $source = self::source($request);
        app(Analytics::class)->record('landing_view', $request, [
            'path' => '/l/'.$prototype->id,
            'referrer' => $request->headers->get('referer'),
            'utm_source' => $request->query('utm_source'),
            'utm_medium' => $request->query('utm_medium'),
            'utm_campaign' => $request->query('utm_campaign'),
            'click_id' => collect(['fbclid', 'gclid', 'msclkid'])->first(fn ($k) => $request->query($k) !== null),
        ], ['prototype' => $prototype->id, 'campaign' => $prototype->parent_id]);

        $html = self::withSignup((string) $prototype->html, $prototype, $lang, $source);
        $csp = implode('; ', [
            "default-src 'none'",
            "style-src 'unsafe-inline'",
            "script-src 'unsafe-inline'",
            'img-src data:',
            'font-src data:',
            // The only request the page may make: the sign-up, to this origin.
            "connect-src 'self'",
            "base-uri 'none'",
            "form-action 'none'",
            "frame-ancestors 'self' https://appwerk.codemenschen.at",
        ]);

        // A campaign's test page is kept out of search; a bought website wants to be found.
        return response($html, 200, array_filter([
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => $prototype->project_id === null ? 'noindex' : null,
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]));
    }

    /**
     * A bought website on the customer's own domain: the request arrives with their host name
     * (Apache passes it through), and the project that asked for that domain is the page. Any
     * other host gets the API's plain front page, as before.
     */
    public function byHost(Request $request): Response|View
    {
        $host = preg_replace('~^www\.~', '', strtolower($request->getHost()));
        $own = preg_replace('~^www\.~', '', strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)));
        $project = $host === $own ? null : Project::where('kind', 'site')->where('status', 'PUBLISHED')->where('domain', $host)->first();
        if ($project === null || $project->prototype === null) {
            return view('welcome');
        }

        return $this->page($request, $project->prototype);
    }

    /** The form. Always answers the same to a visitor, so it tells nobody who is on the list. */
    public function signup(Request $request, Prototype $prototype): JsonResponse
    {
        abort_unless(self::isLanding($prototype) && $prototype->published_at !== null && $prototype->isLive(), 404);
        $data = $request->validate(['email' => 'required|email|max:190', 'hp' => 'nullable|string|max:200',
            'source' => 'nullable|string|max:120']);
        // The field a person never sees. A bot fills it, and is told it worked.
        if (trim((string) ($data['hp'] ?? '')) !== '') {
            return response()->json(['ok' => true]);
        }
        $email = strtolower(trim($data['email']));
        $lang = self::lang($prototype);

        $row = LandingSignup::firstOrNew(['prototype_id' => $prototype->id, 'email' => $email]);
        if ($row->status === 'confirmed' || ($row->mailed_at !== null && $row->mailed_at->gt(now()->subMinutes(10)))) {
            return response()->json(['ok' => true]);
        }
        $today = LandingSignup::where('prototype_id', $prototype->id)->where('mailed_at', '>=', now()->startOfDay())->count();
        if ($today >= self::MAILS_PER_DAY) {
            Log::warning('landing: daily mail cap reached', ['prototype' => $prototype->id]);

            return response()->json(['error' => 'Too many sign-ups today, try again tomorrow.'], 429);
        }

        $row->fill([
            'status' => 'pending',
            'consent' => self::consentText($prototype, $lang),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'source' => isset($data['source']) ? mb_substr($data['source'], 0, 120) : null,
            'mailed_at' => now(),
        ])->save();
        self::sendConfirm($row, $prototype, $lang);
        app(Analytics::class)->record('landing_signup', $request, [], ['prototype' => $prototype->id, 'campaign' => $prototype->parent_id]);

        return response()->json(['ok' => true]);
    }

    /** The link in the confirmation mail. */
    public function confirm(Request $request, LandingSignup $signup): Response
    {
        abort_unless($request->hasValidSignature(), 403, 'Link expired or invalid');
        $prototype = $signup->prototype;
        $lang = self::lang($prototype);
        if ($signup->status !== 'confirmed') {
            $signup->update(['status' => 'confirmed', 'confirmed_at' => now(), 'confirm_ip' => $request->ip()]);
            app(Analytics::class)->record('landing_confirmed', $request, [], ['prototype' => $prototype->id, 'campaign' => $prototype->parent_id]);
        }

        return self::note($lang === 'de' ? 'Danke, du bist dabei.' : 'Thank you, you are on the list.',
            $lang === 'de' ? 'Deine Anmeldung bei '.self::name($prototype).' ist bestätigt. Wir melden uns per E-Mail.'
                : 'Your sign-up with '.self::name($prototype).' is confirmed. We will be in touch by e-mail.', $lang);
    }

    /** The link at the end of every mail: the address is deleted, not only marked. */
    public function remove(Request $request, LandingSignup $signup): Response
    {
        abort_unless($request->hasValidSignature(), 403, 'Link expired or invalid');
        $lang = self::lang($signup->prototype);
        $signup->delete();

        return self::note($lang === 'de' ? 'Abgemeldet.' : 'Unsubscribed.',
            $lang === 'de' ? 'Deine E-Mail-Adresse ist gelöscht. Du bekommst keine E-Mails mehr.'
                : 'Your e-mail address has been deleted. You will get no more e-mails.', $lang);
    }

    public static function url(Prototype $prototype): string
    {
        return rtrim((string) config('app.url'), '/').'/l/'.$prototype->id;
    }

    /** A page this controller serves: a campaign's landing page, or a website somebody bought. */
    public static function isLanding(Prototype $prototype): bool
    {
        return $prototype->kind === 'site' && $prototype->html !== null && ($prototype->parent_id !== null || $prototype->project_id !== null);
    }

    private function authorizeOwner(Request $request, Prototype $prototype): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || ($prototype->customer_id !== null && $prototype->customer_id === $user->id), 403, 'This campaign belongs to another account.');
    }

    /** The page's own language: the mails and the consent line speak it. German or English for now. */
    private static function lang(Prototype $prototype): string
    {
        return preg_match('~<html[^>]*\blang=["\']?de~i', (string) $prototype->html) === 1 ? 'de' : 'en';
    }

    /** The business, as the mails name it: the page title up to its first separator. */
    public static function name(Prototype $prototype): string
    {
        $title = trim((string) preg_split('~\s[|:\x{2013}\x{2014}-]\s|:\s~u', (string) $prototype->title)[0]);

        return mb_substr($title !== '' ? $title : 'Appwerk', 0, 60);
    }

    /** Where the visitor came from, kept with the address: the ad, a post, a mail. */
    private static function source(Request $request): ?string
    {
        foreach (['utm_source', 'utm_campaign'] as $k) {
            if (is_string($v = $request->query($k)) && $v !== '') {
                return mb_substr($v, 0, 120);
            }
        }

        return $request->query('fbclid') !== null ? 'facebook' : null;
    }

    /** What the visitor agrees to, word for word, stored with each address. */
    public static function consentText(Prototype $prototype, string $lang): string
    {
        $name = self::name($prototype);

        return $lang === 'de'
            ? "Mit der Anmeldung bekommst du E-Mails von $name zu diesem Angebot. Du bestätigst per Link und kannst dich jederzeit mit einem Klick abmelden. Die Seite und die Liste betreibt Appwerk (Codemenschen GmbH) für $name."
            : "When you sign up you get e-mails from $name about this offer. You confirm by link and can unsubscribe at any time with one click. The page and the list are run by Appwerk (Codemenschen GmbH) for $name.";
    }

    private static function sendConfirm(LandingSignup $row, Prototype $prototype, string $lang): void
    {
        $confirm = URL::temporarySignedRoute('landing.confirm', now()->addDays(7), ['signup' => $row->id]);
        $remove = URL::signedRoute('landing.remove', ['signup' => $row->id]);
        $name = self::name($prototype);
        if (config('mail.default') === 'log') {
            Log::info('landing.confirm_link', ['email' => $row->email, 'url' => $confirm]);

            return;
        }
        $body = $lang === 'de'
            ? "Hallo,\n\ndu hast dich bei $name angemeldet. Bitte bestätige deine E-Mail-Adresse mit einem Klick:\n\n$confirm\n\nErst dann stehst du auf der Liste. Wenn du dich nicht angemeldet hast, ignoriere diese E-Mail, dann passiert nichts.\n\n$name\n\nAbmelden und Adresse löschen: $remove"
            : "Hello,\n\nyou signed up with $name. Please confirm your e-mail address with one click:\n\n$confirm\n\nOnly then are you on the list. If you did not sign up, ignore this e-mail and nothing happens.\n\n$name\n\nUnsubscribe and delete your address: $remove";
        Mail::raw($body, function ($m) use ($row, $lang, $name, $remove) {
            $m->to($row->email)->subject($lang === 'de' ? "Bitte bestätige deine Anmeldung bei $name" : "Please confirm your sign-up with $name");
            $m->getHeaders()->addTextHeader('List-Unsubscribe', "<$remove>");
        });
    }

    /** The page as generated, with the form wired to the waitlist and the consent line under it. */
    public static function withSignup(string $html, Prototype $prototype, string $lang, ?string $source): string
    {
        $de = $lang === 'de';
        $privacy = rtrim((string) config('services.frontend_url'), '/').'/'.($de ? 'de' : 'en').'/privacy';
        $t = [
            'endpoint' => '/api/landing/'.$prototype->id.'/signup',
            'source' => $source,
            'consent' => e(self::consentText($prototype, $lang)).' <a href="'.e($privacy).'" target="_blank" rel="noopener">'.($de ? 'Datenschutz' : 'Privacy').'</a>',
            'check' => $de ? 'Fast geschafft. Wir haben dir eine E-Mail geschickt: bitte bestätige deine Anmeldung mit dem Link darin.'
                : 'Almost done. We sent you an e-mail: please confirm your sign-up with the link in it.',
            'invalid' => $de ? 'Bitte gib eine gültige E-Mail-Adresse ein.' : 'Please enter a valid e-mail address.',
            'error' => $de ? 'Das hat nicht geklappt. Versuch es bitte gleich nochmal.' : 'That did not work. Please try again in a moment.',
        ];
        $json = json_encode($t, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        $inject = <<<HTML
<style>.aw-consent{font-size:12px;line-height:1.4;opacity:.75;margin:8px 0 0;flex-basis:100%;max-width:520px}.aw-consent a{color:inherit;text-decoration:underline}.aw-done{font-weight:600;margin:0;padding:12px 0}.aw-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;opacity:0}.aw-foot{font:12px/1.4 system-ui,sans-serif;text-align:center;padding:16px;opacity:.6}.aw-foot a{color:inherit}</style>
<script>(function(){var T=$json;
function forms(){return Array.prototype.filter.call(document.querySelectorAll('form'),function(f){return f.querySelector('input[type=email]')})}
function ready(){forms().forEach(function(f){if(f.dataset.aw)return;f.dataset.aw='1';var p=document.createElement('p');p.className='aw-consent';p.innerHTML=T.consent;f.appendChild(p);var h=document.createElement('input');h.type='text';h.name='aw_hp';h.tabIndex=-1;h.autocomplete='off';h.className='aw-hp';h.setAttribute('aria-hidden','true');f.appendChild(h)})}
function send(f){if(f.dataset.busy)return;var em=f.querySelector('input[type=email]');var v=(em.value||'').trim();if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)){em.setCustomValidity(T.invalid);em.reportValidity();em.setCustomValidity('');return}f.dataset.busy='1';var hp=f.querySelector('.aw-hp');
fetch(T.endpoint,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({email:v,hp:hp?hp.value:'',source:T.source})}).then(function(r){if(!r.ok)throw 0;var d=document.createElement('p');d.className='aw-done';d.setAttribute('role','status');d.textContent=T.check;f.replaceWith(d)}).catch(function(){delete f.dataset.busy;alert(T.error)})}
document.addEventListener('submit',function(e){var f=e.target;if(!f.querySelector||!f.querySelector('input[type=email]'))return;e.preventDefault();e.stopImmediatePropagation();send(f)},true);
document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('button,input[type=submit]');var f=b&&b.closest('form');if(!f||!f.querySelector('input[type=email]'))return;e.preventDefault();e.stopImmediatePropagation();send(f)},true);
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);else ready()})();</script>
HTML;
        $foot = '<div class="aw-foot">'.($de ? 'Erstellt mit' : 'Made with').' <a href="'.e(rtrim((string) config('services.frontend_url'), '/')).'" target="_blank" rel="noopener">Appwerk</a> · <a href="'.e($privacy).'" target="_blank" rel="noopener">'.($de ? 'Datenschutz' : 'Privacy').'</a></div>';

        $html = stripos($html, '</head>') !== false ? preg_replace('~</head>~i', $inject.'</head>', $html, 1) : $inject.$html;

        return stripos($html, '</body>') !== false ? preg_replace('~</body>(?![\s\S]*</body>)~i', $foot.'</body>', $html, 1) : $html.$foot;
    }

    private static function note(string $title, string $text, string $lang): Response
    {
        $page = '<!doctype html><html lang="'.$lang.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title>'
            .'<style>body{font:17px/1.5 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;background:#f6f5f2;color:#1d1d1f}main{max-width:460px;padding:32px 20px}h1{font-size:26px;margin:0 0 8px}</style></head>'
            .'<body><main><h1>'.e($title).'</h1><p>'.e($text).'</p></main></body></html>';

        return response($page, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'"]);
    }
}
