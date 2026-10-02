<?php

namespace App\Services;

use App\Domain\Ads\Conversions;
use App\Domain\Analytics\Analytics;
use App\Domain\Sites\SiteService;
use App\Models\Order;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderFulfillment
{
    /**
     * Payment confirmed → order paid, quote consumed, project created.
     * Without the FAGG § 18 waiver the build start is deferred 14 days so the
     * withdrawal right stays intact (legal-02 §4 flow).
     */
    public function markPaid(Order $order, ?string $paymentIntent, int $amountEur, array $rawEvent): Project
    {
        // The webhook has no visitor; the quote ties the order back to the visit that made it.
        app(Analytics::class)->record('order_paid', null, ['quote_id' => $order->quote_id, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'locale' => $order->locale], [
            'amount_eur' => $amountEur, 'listing' => $order->quote?->listing_slug,
        ]);
        // Reported to the ad platform only when the quote carries a consented ad click. A failure
        // here must never stand between a payment and the project it paid for.
        try {
            if ($order->quote !== null) {
                app(Conversions::class)->purchase($order->quote, (float) $amountEur, $order->customer?->email);
            }
        } catch (\Throwable $e) {
            Log::warning('conversion.purchase_failed', ['order' => $order->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        $project = DB::transaction(function () use ($order, $paymentIntent, $amountEur, $rawEvent) {
            $order->update(['status' => 'paid']);
            $order->payments()->create([
                'stripe_payment_intent' => $paymentIntent,
                'amount_eur' => $amountEur,
                'status' => 'succeeded',
                'raw_event' => $rawEvent,
            ]);
            $order->quote->update(['status' => 'converted']);

            $quote = $order->quote;
            $site = $quote->kind === 'site';
            $name = $quote->listing_slug
                ? ucfirst($quote->listing_slug)
                : mb_substr($quote->idea ?? ($site ? 'Website' : 'Custom app'), 0, 60);

            $project = Project::create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'name' => $name,
                'kind' => $site ? 'site' : 'app',
                'status' => 'PAID',
                'stack' => $site ? 'site' : (($quote->platform ?? 'mobile') === 'web' ? 'nextjs' : 'expo'),
                'build_starts_at' => $order->fagg_waiver ? now() : now()->addDays(14),
            ]);
            $project->recordEvent('project.created', [
                'order_id' => $order->id,
                'fagg_waiver' => $order->fagg_waiver,
                'build_starts_at' => $project->build_starts_at->toIso8601String(),
            ]);

            // A website is the bought preview: nothing to build, it goes live now, or when the
            // withdrawal period is over (pipeline:tick). Immediate-start apps enter the pipeline
            // right away; deferred ones are picked up by pipeline:tick when the FAGG period ends.
            if ($site) {
                $sites = app(SiteService::class);
                $sites->claim($project, $quote->prototype, $order);
                if (! $project->build_starts_at->isFuture()) {
                    $sites->goLive($project);
                }
            } elseif (! $project->build_starts_at->isFuture()) {
                app(PipelineOrchestrator::class)->start($project);
            }

            return $project;
        });

        // After the commit, so the mail never names a project the database does not have. The
        // success page has promised this mail since the first order; it was sent for the first
        // time on 2026-09-07.
        app(CustomerMail::class)->orderPaid($order->fresh(), $project);

        return $project;
    }
}
