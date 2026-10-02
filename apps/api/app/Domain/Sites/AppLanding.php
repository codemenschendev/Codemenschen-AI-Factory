<?php

namespace App\Domain\Sites;

use App\Jobs\BuildPrototype;
use App\Models\Project;
use App\Models\Prototype;
use App\Services\Notify;

/**
 * The landing page an app customer buys with the app (package `landingPage`, 2026-10-02).
 *
 * The site writer builds it from the order and the app's acceptance criteria: the same machinery as
 * a website prototype, told that the page is the app's home with a waiting list. It starts once the
 * product stage has written the spec (so after the withdrawal period when the customer did not
 * waive it), goes online by itself when it is ready,
 * and is served at /l/{id} like a bought website, Impressum, own domain and sign-ups included.
 * The App-Marketing kit (JSON-LD, FAQ, llms.txt) is laid onto it when it is served, so a kit that
 * comes later still lands on the page.
 */
class AppLanding
{
    private const ROLE = [
        'de' => 'Diese Website ist die Landingpage einer neuen App. Eine Seite: was die App für wen tut, die wichtigsten Funktionen, '
            .'für welche Geräte sie ist, kurze Fragen und Antworten, und ein Anmeldeformular (ein E-Mail-Feld und ein Knopf, '
            .'"Benachrichtige mich, wenn die App erscheint") oben und noch einmal am Ende. Das Formular sendet nichts: nach dem Absenden '
            .'zeigt es an seiner Stelle ein kurzes Danke. Keine erfundenen Nutzerzahlen, Bewertungen oder Preise. Schreib die Seite auf Deutsch.',
        'en' => 'This website is the landing page of a new app. One page: what the app does and for whom, its main features, '
            .'which devices it runs on, a few questions and answers, and a sign-up form (an e-mail field and one button, '
            .'"Tell me when the app is out") near the top and again at the end. The form sends nothing: on submit it shows a short '
            .'thank-you in place of the form. No invented user numbers, ratings or prices. Write the page in English.',
    ];

    /** Whether this project bought a landing page with its app. */
    public static function bought(Project $project): bool
    {
        return $project->kind !== 'site' && ! empty($project->order?->packages['landingPage']);
    }

    /** Queue the page once. Safe to call again: a project has one page. */
    public function start(Project $project): void
    {
        if (! self::bought($project) || $project->prototype()->exists()) {
            return;
        }
        $order = $project->order;
        $quote = $order->quote;
        $lang = ($order->locale ?? $quote?->locale) === 'en' ? 'en' : 'de';

        $proto = Prototype::create([
            'status' => 'queued',
            'kind' => 'site',
            'prompt' => self::prompt($project->name, $quote?->idea, $quote?->platform, $quote?->audience, $quote?->features ?? [],
                $project->criteria()->limit(10)->pluck('criterion')->all(), $lang),
            'project_id' => $project->id,
            'customer_id' => $project->customer_id,
            'expires_at' => null,
            'qa' => ['app_landing' => true],
        ]);
        BuildPrototype::dispatch($proto->id, 'site')->afterCommit();
        $project->recordEvent('landing.started', ['prototype_id' => $proto->id]);
    }

    /** Called when the writer is done: an app's page goes online by itself. */
    public static function ready(Prototype $proto): void
    {
        $project = $proto->project;
        if ($project === null || ! self::bought($project) || $proto->published_at !== null || $proto->status !== 'ready') {
            return;
        }
        $proto->update(['published_at' => now()]);
        $project->recordEvent('landing.live', ['url' => SiteService::url($project->fresh())]);
        app(Notify::class)->note($project, 'landing page is online: '.SiteService::url($project->fresh()).' (check it once).');
    }

    /** Readable names of the wizard's feature keys (Estimator::FEATURES). */
    private const FEATURES = [
        'de' => ['auth' => 'Nutzerkonten', 'pay' => 'Zahlungen', 'dash' => 'Statistiken', 'ai' => 'KI-Funktionen',
            'notif' => 'Push-Benachrichtigungen', 'api' => 'Anbindung an andere Dienste', 'offline' => 'funktioniert offline', 'i18n' => 'mehrsprachig'],
        'en' => ['auth' => 'user accounts', 'pay' => 'payments', 'dash' => 'statistics', 'ai' => 'AI features',
            'notif' => 'push notifications', 'api' => 'connections to other services', 'offline' => 'works offline', 'i18n' => 'multilingual'],
    ];

    /**
     * @param  list<string>  $features  wizard keys
     * @param  list<string>  $criteria  what the app must do, from the product stage
     */
    public static function prompt(string $name, ?string $idea, ?string $platform, ?string $audience, array $features, array $criteria, string $lang): string
    {
        $features = array_map(fn ($f) => self::FEATURES[$lang][$f] ?? $f, $features);
        $devices = match ($platform) {
            'web' => $lang === 'de' ? 'im Browser' : 'in the browser',
            'both' => $lang === 'de' ? 'im Browser, auf iPhone und Android' : 'in the browser, on iPhone and Android',
            default => $lang === 'de' ? 'auf iPhone und Android' : 'on iPhone and Android',
        };
        $lines = array_filter([
            'App: '.$name,
            $idea ? ($lang === 'de' ? 'Was sie tut: ' : 'What it does: ').$idea : null,
            ($lang === 'de' ? 'Läuft ' : 'Runs ').$devices,
            $audience ? ($lang === 'de' ? 'Für: ' : 'For: ').match ($audience) {
                'b2b' => $lang === 'de' ? 'Unternehmen' : 'businesses',
                'consumer' => $lang === 'de' ? 'Privatpersonen' : 'consumers',
                default => $lang === 'de' ? 'Privatpersonen und Unternehmen' : 'consumers and businesses',
            } : null,
            $features ? ($lang === 'de' ? 'Funktionen: ' : 'Features: ').implode(', ', $features) : null,
            $criteria ? ($lang === 'de' ? "Was die App kann (aus der Spezifikation, für die Seite in einfache Worte fassen):\n- " : "What the app does (from its specification, put it in plain words for the page):\n- ")
                .implode("\n- ", $criteria) : null,
        ]);

        return self::ROLE[$lang]."\n\n".implode("\n", $lines);
    }
}
