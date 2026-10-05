<?php

namespace App\Services;

use App\Domain\Pricing\Estimator;
use App\Domain\Sites\MockupSite;
use App\Domain\Sites\SiteService;
use App\Http\Controllers\LandingController;
use App\Mail\CustomerNotice;
use App\Models\ChangeRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Project;
use App\Support\MailLink;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * What a customer hears from us, and when.
 *
 * Until 2026-09-07 nothing: the success page promised "you will get an e-mail with a sign-in
 * link", the webhook created the project in silence, and the operator got every mail instead.
 * A customer who paid 400 euro and heard nothing has one question and it is not a good one.
 *
 * Three moments: paid (here is your project and how to get in), preview ready (please look and
 * approve), and failed (we saw it, a person is on it). Every mail carries a sign-in link that
 * lasts a day, because the one from the login form lasts thirty minutes and a mail is read
 * whenever it is read.
 *
 * Plain sentences, no dash as a sentence break, the same rule as every other customer-facing
 * line. Failures never break the caller: a mail that could not be sent is logged and the
 * order, the project and the pipeline go on.
 */
class CustomerMail
{
    public function orderPaid(Order $order, Project $project): void
    {
        $customer = $order->customer;
        if ($customer === null) {
            return;
        }
        $de = $this->german($order->locale ?? $customer->locale);
        $link = $this->signIn($customer, $de ? 'de' : 'en');
        $start = $project->build_starts_at;
        $today = $start === null || ! $start->isFuture();

        $subject = $de
            ? "Deine Bestellung bei Appmitki: {$project->name}"
            : "Your Appmitki order: {$project->name}";

        if ($project->kind === 'site') {
            $this->send($customer, $subject, $this->siteBody($order, $project, $customer, $de, $link));

            return;
        }
        if ($project->kind === 'plugin') {
            $this->send($customer, $de ? "Deine Bestellung bei Sofabuilt: {$project->name}" : "Your Sofabuilt order: {$project->name}",
                $this->pluginBody($order, $project, $customer, $de, $today ? null : $start), 'Sofabuilt');

            return;
        }

        $body = $de ? implode("\n", [
            $this->hello($customer, true),
            '',
            'danke, deine Zahlung ist angekommen und dein Projekt ist angelegt.',
            '',
            "Projekt: {$project->name}",
            "Betrag: {$order->total_one_time_eur} Euro",
            $today
                ? 'Baustart: sofort. Die Factory arbeitet bereits an der ersten Vorschau.'
                : 'Baustart: '.$start->format('d.m.Y').', nach Ablauf der 14-tägigen Widerrufsfrist.',
            '',
            'Dein Projekt-Dashboard (Link 24 Stunden gültig):',
            $link,
            '',
            'Was jetzt passiert: die Factory schreibt die Spezifikation, baut die App und testet sie. Sobald die erste Vorschau im Browser steht, bekommst du eine E-Mail. Du prüfst sie, wünschst Änderungen oder gibst frei. Erst nach deiner Freigabe wird die installierbare App gebaut.',
            '',
            'Fragen jederzeit: einfach auf diese E-Mail antworten.',
            '',
            $this->footer(true),
        ]) : implode("\n", [
            $this->hello($customer, false),
            '',
            'thank you, your payment has arrived and your project is set up.',
            '',
            "Project: {$project->name}",
            "Amount: {$order->total_one_time_eur} euro",
            $today
                ? 'Build start: now. The factory is already working on the first preview.'
                : 'Build start: '.$start->format('d.m.Y').', after the 14-day withdrawal period.',
            '',
            'Your project dashboard (link valid for 24 hours):',
            $link,
            '',
            'What happens next: the factory writes the specification, builds the app and tests it. As soon as the first preview runs in the browser you get an e-mail. You check it, ask for changes or approve it. The installable app is built only after your approval.',
            '',
            'Questions at any time: just reply to this e-mail.',
            '',
            $this->footer(false),
        ]);

        $this->send($customer, $subject, $body);
    }

    /** A bought website: it is live (or when it will be), where, and how the domain and changes work. */
    private function siteBody(Order $order, Project $project, Customer $customer, bool $de, string $link): string
    {
        $start = $project->build_starts_at;
        $now = $start === null || ! $start->isFuture();
        $url = SiteService::url($project) ?? ($project->prototype !== null ? LandingController::url($project->prototype) : '');
        // A design picture is built into the page first (BuildSiteFromMockup), which takes minutes.
        $building = $project->prototype !== null && MockupSite::isMockup($project->prototype);
        $months = Estimator::SITE_HOSTING_FREE_MONTHS;
        $hosting = Estimator::SITE_HOSTING_MONTHLY_EUR;

        return $de ? implode("\n", [
            $this->hello($customer, true),
            '',
            'danke, deine Zahlung ist angekommen.',
            '',
            "Website: {$project->name}",
            "Betrag: {$order->total_one_time_eur} Euro",
            $now ? ($building ? "Wir bauen deine Website jetzt aus dem Entwurf. In der nächsten Stunde ist sie online: {$url}" : "Deine Website ist online: {$url}") : 'Online ab: '.$start->format('d.m.Y').", nach Ablauf der 14-tägigen Widerrufsfrist. Adresse: {$url}",
            '',
            'Dein Dashboard (Link 24 Stunden gültig):',
            $link,
            '',
            'Wichtig: trag im Dashboard dein Impressum ein. In Österreich und Deutschland muss jede Firmen-Website eines haben. Sobald es ausgefüllt ist, verlinkt deine Seite es automatisch.',
            'Eigene Domain: trag sie im Dashboard ein. Wir verbinden sie innerhalb von einem Werktag und sagen dir, welchen DNS-Eintrag du setzen musst.',
            'Änderungen: eine Änderung ist inklusive, direkt auf der Vorschau-Seite. Für weitere Änderungen antworte einfach auf diese E-Mail.',
            "Hosting: die ersten {$months} Monate sind inklusive, danach {$hosting} Euro im Monat. Wir melden uns rechtzeitig vorher.",
            '',
            'Fragen jederzeit: einfach auf diese E-Mail antworten.',
            '',
            $this->footer(true),
        ]) : implode("\n", [
            $this->hello($customer, false),
            '',
            'thank you, your payment has arrived.',
            '',
            "Website: {$project->name}",
            "Amount: {$order->total_one_time_eur} euro",
            $now ? ($building ? "We are building your website from the design now. It will be live within the hour: {$url}" : "Your website is live: {$url}") : 'Live from: '.$start->format('d.m.Y').", after the 14-day withdrawal period. Address: {$url}",
            '',
            'Your dashboard (link valid for 24 hours):',
            $link,
            '',
            'Important: enter your legal notice (Impressum) in the dashboard. In Austria and Germany every business website must have one. Once it is filled in, your page links it automatically.',
            'Your own domain: enter it in the dashboard. We connect it within one working day and tell you which DNS record to set.',
            'Changes: one change is included, right on the preview page. For further changes, just reply to this e-mail.',
            "Hosting: the first {$months} months are included, then {$hosting} euro a month. We tell you in good time.",
            '',
            'Questions at any time: just reply to this e-mail.',
            '',
            $this->footer(false),
        ]);
    }

    /** REVIEW, READY and FAILED are the moments a customer should hear about; the rest is ours. */
    public function projectStatus(Project $project, string $to, ?string $from = null): void
    {
        $customer = $project->customer;
        if ($customer === null || ! in_array($to, ['REVIEW', 'READY', 'FAILED'], true)) {
            return;
        }
        // FIXING straight back to REVIEW is a round that changed nothing (declined or failed).
        // There is no new preview to announce; roundEnded() says what happened instead.
        if ($to === 'REVIEW' && $from === 'FIXING') {
            return;
        }
        // The language of the order, not of the customer record: a customer who ordered in
        // German on an account created in English got "Your preview is ready".
        $de = $this->german($project->order?->locale ?? $customer->locale);
        $link = $this->signIn($customer, $de ? 'de' : 'en');
        $name = $project->name;
        if ($project->kind === 'plugin') {
            $this->pluginStatus($project, $customer, $to, $de, $link);

            return;
        }

        [$subject, $lines] = match (true) {
            $to === 'REVIEW' && $project->revision_rounds > 0 => $de
                ? ["Deine Änderung ist umgesetzt: {$name}", [
                    'die Änderungsrunde ist fertig und die neue Vorschau steht.',
                    '',
                    'Im Projekt siehst du, was bei jedem Punkt gemacht wurde. Schau dir die Vorschau an und gib die App frei oder wünsch dir noch etwas.',
                ]]
                : ["Your change is done: {$name}", [
                    'the change round is finished and the new preview is up.',
                    '',
                    'The project shows what was done for each point. Take a look at the preview, then approve the app or ask for something else.',
                ]],
            $to === 'REVIEW' => $de
                ? ["Deine Vorschau ist fertig: {$name}", [
                    'die erste Vorschau deiner App steht und läuft im Browser.',
                    '',
                    'Bitte schau sie dir an. Du kannst Änderungen wünschen (drei Runden sind inklusive) oder die App freigeben. Erst nach der Freigabe bauen wir die installierbare App.',
                ]]
                : ["Your preview is ready: {$name}", [
                    'the first preview of your app is up and runs in the browser.',
                    '',
                    'Please take a look. You can ask for changes (three rounds are included) or approve the app. The installable app is built only after your approval.',
                ]],
            $to === 'READY' => $de
                ? ["Freigabe erhalten: {$name}", [
                    'danke für die Freigabe. Die installierbare App wird jetzt gebaut. Das dauert in der Regel unter einer Stunde, dann liegt sie in deinem Dashboard zum Download bereit.',
                ]]
                : ["Approval received: {$name}", [
                    'thank you for the approval. The installable app is being built now. That usually takes under an hour, then it is in your dashboard for download.',
                ]],
            default => $de
                ? ["Wir haben ein Problem gesehen: {$name}", [
                    'beim Bau deiner App ist etwas schiefgegangen. Ein Mensch bei uns schaut sich das jetzt an. Du musst nichts tun, wir melden uns, sobald es weitergeht.',
                ]]
                : ["We saw a problem: {$name}", [
                    'something went wrong while building your app. A person on our side is looking at it now. Nothing to do for you, we will be in touch as soon as it moves on.',
                ]],
        };

        $body = implode("\n", array_merge(
            [$this->hello($customer, $de), ''],
            $lines,
            ['', $de ? 'Dein Projekt-Dashboard (Link 24 Stunden gültig):' : 'Your project dashboard (link valid for 24 hours):', $link, '', $this->footer($de)],
        ));

        $this->send($customer, $subject, $body);
    }

    /**
     * A round ended without a new preview: the agent declined it, or it did not work. Before this
     * mail the customer heard "your preview is ready" about a preview that had not changed.
     */
    public function roundEnded(Project $project, ChangeRequest $cr): void
    {
        $customer = $project->customer;
        if ($customer === null || ! in_array($cr->status, ['out_of_scope', 'failed'], true)) {
            return;
        }
        $de = $this->german($project->order?->locale ?? $customer->locale);
        $name = $project->name;
        $declined = $cr->status === 'out_of_scope';
        $refund = $cr->price_eur > 0;

        $subject = $de
            ? ($declined ? "Deine Änderung wurde nicht umgesetzt: {$name}" : "Deine Änderung hat nicht geklappt: {$name}")
            : ($declined ? "Your change was not made: {$name}" : "Your change did not work: {$name}");
        $lines = $de
            ? ($declined
                ? ["dein Änderungswunsch aus Runde {$cr->round} passt nicht in eine Änderungsrunde, zum Beispiel weil er eine neue Funktion wäre. An der App wurde nichts geändert.", '', 'Die Begründung steht im Projekt. Dort kannst du den Wunsch anpassen oder ein Angebot anfragen.']
                : ["die Änderungsrunde {$cr->round} hat technisch nicht geklappt. An der App wurde nichts geändert. Unser Team ist informiert und meldet sich bei dir."])
            : ($declined
                ? ["your change request from round {$cr->round} does not fit a change round, for example because it would be a new feature. Nothing in the app was changed.", '', 'The reason is in the project. You can adjust the request there or ask for a quote.']
                : ["change round {$cr->round} did not work on our side. Nothing in the app was changed. Our team knows and will get back to you."]);
        if ($refund) {
            $lines[] = '';
            $lines[] = $de
                ? "Die {$cr->price_eur} Euro für diese Runde bekommst du zurück."
                : "You get the {$cr->price_eur} euro for this round back.";
        }

        $this->send($customer, $subject, $this->withLink($customer, $de, $lines));
    }

    /** A person from the team answered in the change chat and the customer is not looking. */
    public function operatorReplied(Project $project, string $reply): void
    {
        $customer = $project->customer;
        if ($customer === null) {
            return;
        }
        $de = $this->german($project->order?->locale ?? $customer->locale);
        $name = $project->name;
        $quote = '> '.str_replace("\n", "\n> ", mb_strimwidth(trim($reply), 0, 600, ' ...'));

        $subject = $de ? "Antwort vom Appmitki Team: {$name}" : "Reply from the Appmitki team: {$name}";
        $lines = $de
            ? ['unser Team hat dir im Projekt geantwortet:', '', $quote, '', 'Bitte antworte direkt im Projekt, dann bleibt alles an einem Ort.']
            : ['our team replied to you in the project:', '', $quote, '', 'Please answer in the project, so everything stays in one place.'];

        $this->send($customer, $subject, $this->withLink($customer, $de, $lines));
    }

    /** @param list<string> $lines */
    private function withLink(Customer $customer, bool $de, array $lines): string
    {
        return implode("\n", array_merge(
            [$this->hello($customer, $de), ''],
            $lines,
            ['', $de ? 'Dein Projekt-Dashboard (Link 24 Stunden gültig):' : 'Your project dashboard (link valid for 24 hours):', $this->signIn($customer, $de ? 'de' : 'en'), '', $this->footer($de)],
        ));
    }

    private function signIn(Customer $customer, string $locale): string
    {
        return MailLink::signed('auth.verify', now()->addDay(), [
            'customer' => $customer->id,
            'locale' => $locale,
        ]);
    }

    private function german(?string $locale): bool
    {
        return ($locale ?? 'de') !== 'en';
    }

    private function hello(Customer $customer, bool $de): string
    {
        $name = trim((string) $customer->name);

        return $de
            ? ($name !== '' ? "Hallo {$name}," : 'Hallo,')
            : ($name !== '' ? "Hello {$name}," : 'Hello,');
    }

    private function footer(bool $de): string
    {
        return $de
            ? "Appmitki, ein Angebot der Codemenschen GmbH, Gössendorf.\nDiese E-Mail geht an dich, weil du bei Appmitki bestellt hast."
            : "Appmitki, a service of Codemenschen GmbH, Gössendorf, Austria.\nYou receive this e-mail because you ordered at Appmitki.";
    }

    /** A Sofabuilt plugin's preview, approval or problem, in Sofabuilt's name. */
    private function pluginStatus(Project $project, Customer $customer, string $to, bool $de, string $link): void
    {
        $name = $project->name;
        $try = $project->previewUrl();
        $zip = rtrim(config('app.url'), '/')."/api/plugin/{$project->id}/plugin.zip";
        $sell = ! empty($project->order?->packages['sellReady']);
        $chrome = $project->stack === 'chrome-ext';
        [$subject, $lines] = match (true) {
            $to === 'REVIEW' => $de
                ? [($chrome ? "Deine Erweiterung ist bereit zum Ausprobieren: " : "Dein Plugin ist bereit zum Ausprobieren: ").$name, [
                    $project->revision_rounds > 0 ? 'die Änderungen sind umgesetzt und getestet.' : 'dein Plugin ist gebaut und getestet.',
                    '',
                    ...($chrome ? [
                        'Probier sie in Chrome aus: lade die ZIP herunter und entpacke sie, öffne chrome://extensions, schalte oben rechts den Entwicklermodus ein und klicke auf "Entpackte Erweiterung laden". Wähle den entpackten Ordner.',
                        $zip,
                    ] : [
                        'Probier es live aus. Ein WordPress startet im Browser, dein Plugin ist installiert und du bist angemeldet:',
                        (string) $try,
                    ]),
                    '',
                    'Dann gib es in deinem Projekt frei oder wünsch dir Änderungen (Link 24 Stunden gültig):',
                    $link,
                ]]
                : [($chrome ? "Your extension is ready to try: " : "Your plugin is ready to try: ").$name, [
                    $project->revision_rounds > 0 ? 'the changes are done and tested.' : 'your plugin is built and tested.',
                    '',
                    ...($chrome ? [
                        'Try it in Chrome: download the ZIP and unzip it, open chrome://extensions, switch on Developer mode at the top right and click "Load unpacked". Pick the unzipped folder.',
                        $zip,
                    ] : [
                        'Try it live. A WordPress starts in your browser with your plugin installed and you logged in:',
                        (string) $try,
                    ]),
                    '',
                    'Then approve it in your project or ask for changes (link valid for 24 hours):',
                    $link,
                ]],
            $to === 'READY' => $de
                ? [($chrome ? "Deine Erweiterung gehört dir: " : "Dein Plugin gehört dir: ").$name, array_merge([
                    $chrome
                        ? 'danke für die Freigabe. Hier ist deine Erweiterung als ZIP, bereit für den Chrome Web Store oder zum Laden in Chrome:'
                        : 'danke für die Freigabe. Hier ist dein Plugin als ZIP, bereit zum Hochladen unter Plugins > Installieren:',
                    $zip,
                    '',
                    'Den Quellcode findest du in deinem Projekt. Mach vor der Installation ein Backup deiner Website.',
                    $link,
                ], $sell ? ['', 'Bereit zum Verkauf: Lizenzschlüssel, Updates und Checkout sind eingebaut (über Freemius). Lege dir ein kostenloses Konto auf freemius.com an und schick uns eine kurze Antwort. Wir verbinden dein Plugin damit und laden es für den Verkauf hoch.'] : [])]
                : [($chrome ? "Your extension is yours: " : "Your plugin is yours: ").$name, array_merge([
                    $chrome
                        ? 'thank you for approving. Here is your extension as a ZIP, ready for the Chrome Web Store or to load in Chrome:'
                        : 'thank you for approving. Here is your plugin as a ZIP, ready to upload under Plugins > Add New:',
                    $zip,
                    '',
                    'The source code is in your project. Back up your site before you install it.',
                    $link,
                ], $sell ? ['', 'Ready to sell: licence keys, updates and the checkout are built in (through Freemius). Create a free account on freemius.com and send us a short reply. We connect your plugin to it and upload it for sale.'] : [])],
            default => $de
                ? ["Wir haben ein Problem gesehen: {$name}", ['beim Bau deines Plugins ist etwas schiefgegangen. Ein Mensch bei uns schaut sich das jetzt an. Du musst nichts tun.']]
                : ["We saw a problem: {$name}", ['something went wrong while building your plugin. A person on our side is looking at it now. Nothing to do for you.']],
        };
        $footer = $de
            ? "Sofabuilt, ein Angebot der Codemenschen GmbH, Gössendorf.\nDiese E-Mail geht an dich, weil du bei Sofabuilt bestellt hast."
            : "Sofabuilt, a service of Codemenschen GmbH, Gössendorf, Austria.\nYou receive this e-mail because you ordered at Sofabuilt.";

        $this->send($customer, $subject, implode("\n", array_merge([$this->hello($customer, $de), ''], $lines, ['', $footer])), 'Sofabuilt');
    }

    /** A Sofabuilt plugin order (docs/specs/sofabuilt.md). No portal link yet: the review comes by e-mail. */
    private function pluginBody(Order $order, Project $project, Customer $customer, bool $de, ?\Carbon\CarbonInterface $later): string
    {
        $days = $order->quote?->breakdown['delivery_days'] ?? [2, 3];

        return $de ? implode("\n", [
            $this->hello($customer, true),
            '',
            'danke, deine Zahlung ist angekommen. Wir bauen jetzt dein Plugin.',
            '',
            "Plugin: {$project->name}",
            "Betrag: {$order->total_one_time_eur} Euro",
            $later !== null
                ? 'Start: '.$later->format('d.m.Y').', nach Ablauf der 14-tägigen Widerrufsfrist.'
                : "Start: sofort. Rechne mit {$days[0]} bis {$days[1]} Werktagen.",
            '',
            $project->stack === 'chrome-ext'
                ? 'Was jetzt passiert: wir bauen die Erweiterung nach dem vereinbarten Umfang und testen sie. Dann bekommst du sie zum Ausprobieren in Chrome, mit einer kurzen Anleitung. Du gibst frei oder wünschst Änderungen, eine Runde ist inklusive. Danach bekommst du die ZIP für den Chrome Web Store und den Code.'
                : 'Was jetzt passiert: wir bauen das Plugin nach dem vereinbarten Umfang und testen es. Dann bekommst du einen Link, unter dem du es live in einem WordPress im Browser ausprobierst. Du gibst frei oder wünschst Änderungen, eine Runde ist inklusive. Danach bekommst du das Plugin als ZIP und den Code.',
            '',
            'Fragen jederzeit: einfach auf diese E-Mail antworten.',
            '',
            "Sofabuilt, ein Angebot der Codemenschen GmbH, Gössendorf.\nDiese E-Mail geht an dich, weil du bei Sofabuilt bestellt hast.",
        ]) : implode("\n", [
            $this->hello($customer, false),
            '',
            'thank you, your payment has arrived. We are building your plugin now.',
            '',
            "Plugin: {$project->name}",
            "Amount: {$order->total_one_time_eur} euros",
            $later !== null
                ? 'Start: '.$later->format('d M Y').', after the 14-day withdrawal period.'
                : "Start: now. Expect {$days[0]} to {$days[1]} working days.",
            '',
            $project->stack === 'chrome-ext'
                ? 'What happens now: we build the extension to the agreed scope and test it. Then you get it to try in Chrome, with short steps. You approve it or ask for changes, one round is included. After that you get the ZIP for the Chrome Web Store and the code.'
                : 'What happens now: we build the plugin to the agreed scope and test it. Then you get a link where you try it live in a WordPress in your browser. You approve it or ask for changes, one round is included. After that you get the plugin as a ZIP and the code.',
            '',
            'Questions at any time: just reply to this e-mail.',
            '',
            "Sofabuilt, a service of Codemenschen GmbH, Gössendorf, Austria.\nYou receive this e-mail because you ordered at Sofabuilt.",
        ]);
    }

    private function send(Customer $customer, string $subject, string $body, ?string $fromName = null): void
    {
        if (config('mail.default') === 'log') {
            Log::info('customer.mail', ['to' => $customer->email, 'subject' => $subject]);
        }
        try {
            Mail::to($customer->email)->send(new CustomerNotice($subject, $body, $fromName));
        } catch (\Throwable $e) {
            Log::warning('customer.mail_failed', ['to' => $customer->email, 'subject' => $subject, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }
}
