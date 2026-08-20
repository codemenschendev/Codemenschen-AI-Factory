<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderFulfillment;
use Illuminate\Console\Command;

/**
 * Staging-only stand-in for the Stripe webhook while no keys exist:
 * marks a pending order paid and kicks off the pipeline. Refuses to run
 * when Stripe is configured — then the webhook is the only payment truth.
 */
class SimulatePaid extends Command
{
    protected $signature = 'factory:simulate-paid {order}';

    protected $description = 'STAGING: mark an order paid and start its pipeline';

    public function handle(OrderFulfillment $fulfillment): int
    {
        if (config('services.stripe.secret')) {
            $this->error('Stripe is configured — use real payments.');

            return self::FAILURE;
        }
        $order = Order::findOrFail($this->argument('order'));
        if ($order->status === 'paid') {
            $this->error('Order already paid.');

            return self::FAILURE;
        }
        $project = $fulfillment->markPaid($order, 'simulated', $order->total_one_time_eur, ['simulated' => true]);
        $this->info("project {$project->id} created (status {$project->status}, build starts {$project->build_starts_at})");

        return self::SUCCESS;
    }
}
