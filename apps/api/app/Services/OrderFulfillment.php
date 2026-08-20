<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class OrderFulfillment
{
    /**
     * Payment confirmed → order paid, quote consumed, project created.
     * Without the FAGG § 18 waiver the build start is deferred 14 days so the
     * withdrawal right stays intact (legal-02 §4 flow).
     */
    public function markPaid(Order $order, ?string $paymentIntent, int $amountEur, array $rawEvent): Project
    {
        return DB::transaction(function () use ($order, $paymentIntent, $amountEur, $rawEvent) {
            $order->update(['status' => 'paid']);
            $order->payments()->create([
                'stripe_payment_intent' => $paymentIntent,
                'amount_eur' => $amountEur,
                'status' => 'succeeded',
                'raw_event' => $rawEvent,
            ]);
            $order->quote->update(['status' => 'converted']);

            $quote = $order->quote;
            $name = $quote->listing_slug
                ? ucfirst($quote->listing_slug)
                : mb_substr($quote->idea ?? 'Custom app', 0, 60);

            $project = Project::create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'name' => $name,
                'status' => 'PAID',
                'stack' => ($quote->platform ?? 'mobile') === 'web' ? 'nextjs' : 'expo',
                'build_starts_at' => $order->fagg_waiver ? now() : now()->addDays(14),
            ]);
            $project->recordEvent('project.created', [
                'order_id' => $order->id,
                'fagg_waiver' => $order->fagg_waiver,
                'build_starts_at' => $project->build_starts_at->toIso8601String(),
            ]);

            // Immediate-start orders enter the pipeline right away; deferred
            // ones are picked up by pipeline:tick when the FAGG period ends.
            if (! $project->build_starts_at->isFuture()) {
                app(\App\Services\PipelineOrchestrator::class)->start($project);
            }

            return $project;
        });
    }
}
