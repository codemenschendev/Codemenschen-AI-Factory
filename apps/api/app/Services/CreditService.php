<?php

namespace App\Services;

use App\Domain\Console\Edits;
use App\Domain\Payments\StripeKeys;
use App\Models\Customer;
use App\Support\MailLink;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

/** Packs of change credits for the console (config/console.php): checkout and crediting. */
class CreditService
{
    public function createCheckout(Customer $customer, int $edits, string $locale): ?string
    {
        $eur = Edits::packs()[$edits] ?? null;
        $secret = app(StripeKeys::class)->secret();
        if ($eur === null || ! $secret) {
            return null;
        }
        $session = (new StripeClient($secret))->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $eur * 100,
                    'product_data' => ['name' => $locale === 'de' ? "$edits Änderungen" : "$edits changes"],
                ],
            ]],
            'customer_email' => $customer->email,
            'client_reference_id' => "credits:{$customer->id}",
            'invoice_creation' => ['enabled' => true],
            'locale' => $locale,
            'success_url' => MailLink::portal("/$locale/account").'?credits=added',
            'cancel_url' => MailLink::portal("/$locale/account"),
        ]);
        DB::table('credit_purchases')->insert([
            'customer_id' => $customer->id, 'edits' => $edits, 'eur' => $eur,
            'stripe_session_id' => $session->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $session->url;
    }

    /** Stripe confirmed the payment. Idempotent: a session is credited once. */
    public function complete(string $sessionId): void
    {
        DB::transaction(function () use ($sessionId) {
            $row = DB::table('credit_purchases')->where('stripe_session_id', $sessionId)->lockForUpdate()->first();
            if ($row === null || $row->status === 'paid') {
                return;
            }
            DB::table('credit_purchases')->where('id', $row->id)->update(['status' => 'paid', 'updated_at' => now()]);
            Customer::whereKey($row->customer_id)->increment('edit_credits', $row->edits);
        });
    }
}
