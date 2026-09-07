<?php

namespace App\Services;

use App\Mail\CustomerNotice;
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
    public function projectStatus(Project $project, string $to): void
    {
        $customer = $project->customer;
        if ($customer === null || ! in_array($to, ['REVIEW', 'READY', 'FAILED'], true)) {
            return;
        }
        // The language of the order, not of the customer record: a customer who ordered in
        // German on an account created in English got "Your preview is ready".
        $de = $this->german($project->order?->locale ?? $customer->locale);
        $link = $this->signIn($customer, $de ? 'de' : 'en');
        $name = $project->name;

        [$subject, $lines] = match ($to) {
            'REVIEW' => $de
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
            'READY' => $de
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
