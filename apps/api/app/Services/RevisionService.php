<?php

namespace App\Services;

use App\Models\ChangeRequest;
use Stripe\StripeClient;

/**
 * Billing side of paid change requests. The pipeline side (starting the
 * revise stage, returning the app to its previous status) lives in the
 * orchestrator; this class only talks to Stripe.
 */
class RevisionService
{
    /** Creates the Stripe Checkout Session for a quoted change request (no-op when Stripe is unconfigured). */
    public function createCheckout(ChangeRequest $cr): void
    {
        $secret = config('services.stripe.secret');
        if (! $secret) {
            return; // staging: the portal shows the "payment not connected" notice
        }
        $project = $cr->project;
        $locale = $project->order->locale ?: 'de';
        $front = rtrim(config('services.frontend_url'), '/');

        $session = (new StripeClient($secret))->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $cr->price_eur * 100,
                    'product_data' => ['name' => "{$project->name} — change request round {$cr->round}"],
                ],
            ]],
            'customer_email' => $project->customer->email,
            'client_reference_id' => 'cr:'.$cr->id,
            'locale' => $locale,
            'invoice_creation' => ['enabled' => true],
            'success_url' => "$front/$locale/account/{$project->id}?revision=paid",
            'cancel_url' => "$front/$locale/account/{$project->id}",
        ]);
        $cr->update(['stripe_checkout_session_id' => $session->id, 'checkout_url' => $session->url]);
    }

    /** Stripe confirmed the payment → the revise stage starts. Idempotent. */
    public function markPaid(ChangeRequest $cr, ?string $paymentIntent, int $amountEur, array $rawEvent): void
    {
        if ($cr->paid_at) {
            return;
        }
        $cr->update(['paid_at' => now(), 'stripe_payment_intent' => $paymentIntent]);
        $cr->project->recordEvent('changes.paid', [
            'change_request_id' => $cr->id, 'amount_eur' => $amountEur, 'event_id' => $rawEvent['id'] ?? null,
        ]);
        app(PipelineOrchestrator::class)->onRevisionPaid($cr->fresh());
    }
}
