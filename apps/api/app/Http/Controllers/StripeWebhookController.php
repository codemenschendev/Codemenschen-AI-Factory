<?php

namespace App\Http\Controllers;

use App\Models\ChangeRequest;
use App\Models\Order;
use App\Services\OrderFulfillment;
use App\Services\RevisionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    /**
     * Single source of payment truth: checkout.session.completed marks the
     * order paid and creates the project. Idempotent — Stripe retries.
     */
    public function __invoke(Request $request): Response
    {
        $secret = config('services.stripe.webhook_secret');
        abort_if(! $secret, 503, 'Webhook secret not configured');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $secret,
            );
        } catch (\Throwable $e) {
            Log::warning('stripe.webhook.invalid', ['error' => $e->getMessage()]);
            abort(400, 'Invalid signature');
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $ref = (string) $session->client_reference_id;
            if (str_starts_with($ref, 'cr:')) {
                $cr = ChangeRequest::find(substr($ref, 3));
                if ($cr === null) {
                    Log::error('stripe.webhook.unknown_change_request', ['session' => $session->id]);

                    return response('ignored', 200);
                }
                app(RevisionService::class)
                    ->markPaid($cr, $session->payment_intent, (int) ($session->amount_total / 100), $event->toArray());

                return response('ok', 200);
            }
            $order = Order::find($ref);
            if ($order === null) {
                Log::error('stripe.webhook.unknown_order', ['session' => $session->id]);

                return response('ignored', 200);
            }
            if ($order->status !== 'paid') {
                app(OrderFulfillment::class)
                    ->markPaid($order, $session->payment_intent, (int) ($session->amount_total / 100), $event->toArray());
            }
        }

        return response('ok', 200);
    }
}
