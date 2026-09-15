<?php

namespace App\Services;

use App\Mail\CustomerNotice;
use App\Models\ChangeRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

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
            ? "Deine Bestellung bei Appwerk: {$project->name}"
            : "Your Appwerk order: {$project->name}";

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

        $subject = $de ? "Antwort vom Appwerk Team: {$name}" : "Reply from the Appwerk team: {$name}";
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
        return URL::temporarySignedRoute('auth.verify', now()->addDay(), [
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
            ? "Appwerk, ein Angebot der Codemenschen GmbH, Wien.\nDiese E-Mail geht an dich, weil du bei Appwerk bestellt hast."
            : "Appwerk, a service of Codemenschen GmbH, Vienna.\nYou receive this e-mail because you ordered at Appwerk.";
    }

    private function send(Customer $customer, string $subject, string $body): void
    {
        if (config('mail.default') === 'log') {
            Log::info('customer.mail', ['to' => $customer->email, 'subject' => $subject]);
        }
        try {
            Mail::to($customer->email)->send(new CustomerNotice($subject, $body));
        } catch (\Throwable $e) {
            Log::warning('customer.mail_failed', ['to' => $customer->email, 'subject' => $subject, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }
}
