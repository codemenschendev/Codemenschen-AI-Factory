<?php

namespace App\Domain\Payments;

use App\Models\Setting;

/**
 * Which Stripe account mode Appwerk charges in, switched in the admin panel without a deploy.
 *
 * Both key pairs live in the server .env: STRIPE_SECRET / STRIPE_WEBHOOK_SECRET for the sandbox
 * (Stripe test mode) and STRIPE_LIVE_SECRET / STRIPE_LIVE_WEBHOOK_SECRET for real money. The mode
 * is a setting, sandbox by default. Live can only be switched on when both live keys are present
 * and the secret really is a live key. The webhook accepts events signed for either mode, so a
 * test checkout that is still open when the switch happens still completes.
 */
class StripeKeys
{
    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    public function mode(): string
    {
        return Setting::read('payments.mode') === self::LIVE ? self::LIVE : self::SANDBOX;
    }

    public function live(): bool
    {
        return $this->mode() === self::LIVE;
    }

    /** The secret key for the current mode, or null when that mode is not configured. */
    public function secret(): ?string
    {
        $key = $this->live() ? config('services.stripe.live_secret') : config('services.stripe.secret');

        return $key ?: null;
    }

    /** @return list<string> webhook signing secrets of every configured mode */
    public function webhookSecrets(): array
    {
        return array_values(array_filter([
            config('services.stripe.webhook_secret'),
            config('services.stripe.live_webhook_secret'),
        ]));
    }

    /** @return list<string> what stops live mode, empty when it can be switched on */
    public function liveMissing(): array
    {
        $missing = [];
        $secret = (string) config('services.stripe.live_secret');
        if ($secret === '') {
            $missing[] = 'STRIPE_LIVE_SECRET';
        } elseif (! preg_match('/^(sk|rk)_live_/', $secret)) {
            $missing[] = 'STRIPE_LIVE_SECRET (not a live key)';
        }
        if (! config('services.stripe.live_webhook_secret')) {
            $missing[] = 'STRIPE_LIVE_WEBHOOK_SECRET';
        }

        return $missing;
    }

    /** @return array{mode:string, sandbox_configured:bool, live_missing:list<string>} */
    public function status(): array
    {
        return [
            'mode' => $this->mode(),
            'sandbox_configured' => (bool) config('services.stripe.secret'),
            'live_missing' => $this->liveMissing(),
        ];
    }
}
