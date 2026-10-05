<?php

namespace App\Services;

use App\Domain\Payments\StripeKeys;
use App\Domain\Pricing\Estimator;
use App\Domain\Sofabuilt\Platforms;
use App\Models\Project;
use App\Models\Quote;
use Stripe\StripeClient;

/**
 * Care per project, cancel any time (ends with the billing month). Appmitki apps: €9/month,
 * unlimited change rounds. Sofabuilt: the platform's price (config/sofabuilt.php), and the buyer
 * may start it at checkout with free first months (startTrial). Billing side only: Stripe subscription
 * checkout, webhook mirroring and cancellation. What Care unlocks lives in
 * PipelineOrchestrator::changeRequestMode().
 */
class CareService
{
    public function __construct(private Notify $notify) {}

    public static function monthly(Project $project): int
    {
        return $project->kind === 'plugin' && $project->order?->quote ? self::monthlyForQuote($project->order->quote) : Estimator::CARE_MONTHLY_EUR;
    }

    public static function monthlyForQuote(Quote $quote): int
    {
        return $quote->kind === 'plugin' ? Platforms::care(Platforms::of($quote->breakdown['scope'] ?? null)) : Estimator::CARE_MONTHLY_EUR;
    }

    public static function trialMonths(): int
    {
        return (int) config('sofabuilt.care_trial_months', 3);
    }

    /**
     * The buyer ticked Care at checkout: a subscription on the card they just paid with, free until
     * the trial ends, then monthly. Care counts as active from today. A Stripe failure never stops
     * the build; the operator hears about it and offers Care by hand.
     */
    public function startTrial(Project $project, string $stripeCustomer, string $paymentIntent): void
    {
        if ($project->care_status === 'active') {
            return;
        }
        $secret = app(StripeKeys::class)->secret();
        $ends = now()->addMonths(self::trialMonths());
        $subscription = null;
        if ($secret && $stripeCustomer !== '' && $paymentIntent !== '') {
            try {
                $stripe = new StripeClient($secret);
                $method = (string) $stripe->paymentIntents->retrieve($paymentIntent)->payment_method;
                $brand = $project->order?->brand === 'sofabuilt' ? 'Sofabuilt' : 'Appmitki';
                $product = $stripe->products->create(['name' => "$brand Care: {$project->name}"]);
                $subscription = (string) $stripe->subscriptions->create([
                    'customer' => $stripeCustomer,
                    'default_payment_method' => $method,
                    'items' => [['price_data' => ['currency' => 'eur', 'product' => $product->id, 'unit_amount' => self::monthly($project) * 100, 'recurring' => ['interval' => 'month']]]],
                    'trial_end' => $ends->timestamp,
                    'metadata' => ['project_id' => $project->id],
                ])->id;
            } catch (\Throwable $e) {
                $this->notify->note($project, 'Care trial could not start at Stripe: '.mb_substr($e->getMessage(), 0, 160).'. Offer Care by hand.');

                return;
            }
        }
        $project->update(['care_status' => 'active', 'care_stripe_subscription_id' => $subscription, 'care_started_at' => now(), 'care_ends_at' => null]);
        $project->recordEvent('care.trial_started', ['subscription' => $subscription, 'paid_from' => $ends->toDateString()]);
        $this->notify->note($project, 'Care started with '.self::trialMonths().' free months (€'.self::monthly($project).'/month from '.$ends->toDateString().')');
    }

    /** Stripe Checkout (subscription) for this app; null when Stripe is unconfigured (staging). */
    public function createCheckout(Project $project): ?string
    {
        $secret = app(StripeKeys::class)->secret();
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
                    'unit_amount' => self::monthly($project) * 100,
                    'recurring' => ['interval' => 'month'],
                    'product_data' => ['name' => ($project->order?->brand === 'sofabuilt' ? 'Sofabuilt' : 'Appmitki')." Care: {$project->name}"],
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
        $this->notify->note($project, 'Care started (€'.self::monthly($project).'/month)');
    }

    /** Customer cancels: Care stays active until the end of the paid month. */
    public function cancel(Project $project, string $actor): void
    {
        $secret = app(StripeKeys::class)->secret();
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
