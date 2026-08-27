<?php

namespace App\Services;

use App\Domain\Pricing\Estimator;
use App\Models\Project;
use Stripe\StripeClient;

/**
 * Appwerk Care — €9/month per app, unlimited change rounds, cancel any time
 * (ends with the billing month). Billing side only: Stripe subscription
 * checkout, webhook mirroring and cancellation. What Care unlocks lives in
 * PipelineOrchestrator::changeRequestMode().
 */
class CareService
{
    public function __construct(private Notify $notify) {}

    /** Stripe Checkout (subscription) for this app; null when Stripe is unconfigured (staging). */
    public function createCheckout(Project $project): ?string
    {
        $secret = config('services.stripe.secret');
        if (! $secret) {
            return null;
        }
        $locale = $project->order->locale ?: 'de';
        $front = rtrim(config('services.frontend_url'), '/');

        $session = (new StripeClient($secret))->checkout->sessions->create([
            'mode' => 'subscription',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => Estimator::CARE_MONTHLY_EUR * 100,
                    'recurring' => ['interval' => 'month'],
                    'product_data' => ['name' => "Appwerk Care — {$project->name}"],
                ],
            ]],
            'customer_email' => $project->customer->email,
            'client_reference_id' => 'care:'.$project->id,
            'subscription_data' => ['metadata' => ['project_id' => $project->id]],
            'locale' => $locale,
            'success_url' => "$front/$locale/account/{$project->id}?care=started",
            'cancel_url' => "$front/$locale/account/{$project->id}",
        ]);

        return $session->url;
    }

    /** Stripe confirmed the first payment. Idempotent (webhooks retry). */
    public function activate(Project $project, ?string $subscriptionId, array $rawEvent = []): void
    {
        if ($project->care_status === 'active' && $project->care_stripe_subscription_id === $subscriptionId) {
            return;
        }
        $project->update([
            'care_status' => 'active',
            'care_stripe_subscription_id' => $subscriptionId,
            'care_started_at' => now(),
            'care_ends_at' => null,
        ]);
        $project->recordEvent('care.started', ['subscription' => $subscriptionId, 'event_id' => $rawEvent['id'] ?? null]);
        $this->notify->note($project, 'Care started (€'.Estimator::CARE_MONTHLY_EUR.'/month)');
    }

    /** Customer cancels: Care stays active until the end of the paid month. */
    public function cancel(Project $project, string $actor): void
    {
        $secret = config('services.stripe.secret');
        $endsAt = now()->addMonth();
        if ($secret && $project->care_stripe_subscription_id) {
            $sub = (new StripeClient($secret))->subscriptions->update(
                $project->care_stripe_subscription_id,
                ['cancel_at_period_end' => true],
            );
            if (! empty($sub->current_period_end)) {
                $endsAt = now()->setTimestamp((int) $sub->current_period_end);
            }
        }
        $project->update(['care_ends_at' => $endsAt]);
        $project->recordEvent('care.cancel_requested', ['ends_at' => $endsAt->toIso8601String()], $actor);
        $this->notify->note($project, 'Care cancelled, ends '.$endsAt->toDateString());
    }

    /** customer.subscription.updated / .deleted from Stripe. */
    public function onSubscriptionEvent(string $subscriptionId, string $status, bool $deleted): void
    {
        $project = Project::where('care_stripe_subscription_id', $subscriptionId)->first();
        if ($project === null) {
            return;
        }
        $to = $deleted ? 'canceled' : (in_array($status, ['past_due', 'unpaid', 'incomplete_expired'], true) ? 'past_due' : ($status === 'active' ? 'active' : $project->care_status));
        if ($to === $project->care_status) {
            return;
        }
        $project->update(['care_status' => $to] + ($deleted ? ['care_ends_at' => now()] : []));
        $project->recordEvent('care.'.$to, ['subscription' => $subscriptionId, 'stripe_status' => $status]);
        if ($to !== 'active') {
            $this->notify->note($project, "Care $to");
        }
    }
}
