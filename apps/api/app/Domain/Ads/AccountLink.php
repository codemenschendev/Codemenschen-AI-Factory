<?php

namespace App\Domain\Ads;

use App\Models\AdAccountLink;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Connecting a customer's own ad account, in one paste and one click (2026-09-23).
 *
 * Doing it by hand cost hours on our own account: a list of allowed e-mail domains, two admins
 * approving, and a sign-in loop that never ended. None of that is asked of a customer here.
 *
 * Google has two ways in and we try both. The customer adds our address as a user on their own
 * account, which is one paste in their user list, and we prove it by reading the account. We also
 * send a link request from our manager account, which they accept under Managers; that one is the
 * shorter path but it is an account management call, so a Cloud project below Basic API access
 * cannot send it. Meta has no such call, so the customer adds our Business id as a partner.
 *
 * Either way we store no credential of theirs, and they can cut the link from their side at any
 * time. A link on its own spends nothing: campaigns still go through Preflight and SpendGuard.
 */
class AccountLink
{
    public function __construct(private readonly PublisherRegistry $registry) {}

    /** The ids a customer needs to see to do their part. Not secrets. */
    public function ours(): array
    {
        $google = $this->registry->for('google');
        $meta = $this->registry->for('meta');

        return [
            'google_manager_id' => $google instanceof GoogleAdsPublisher ? self::dashed($google->managerId()) : '',
            'google_service_account' => $google instanceof GoogleAdsPublisher ? $google->serviceAccountEmail() : '',
            'meta_business_id' => $meta instanceof MetaAdsPublisher ? $meta->businessId() : '',
        ];
    }

    /**
     * Ten digits with or without dashes for Google, act_… or plain digits for Meta.
     *
     * @return string the id in the shape the platform's own API wants
     */
    public static function normalise(string $platform, string $id): string
    {
        $id = trim($id);
        if ($platform === 'google') {
            $digits = preg_replace('~\D~', '', $id) ?? '';
            if (strlen($digits) !== 10) {
                throw new RuntimeException('A Google Ads customer id is ten digits, for example 123-456-7890.');
            }

            return $digits;
        }
        $digits = preg_replace('~\D~', '', $id) ?? '';
        if (strlen($digits) < 6 || strlen($digits) > 20) {
            throw new RuntimeException('A Meta ad account id looks like act_1234567890.');
        }

        return 'act_'.$digits;
    }

    /** Asks for the link. Creates the row first, so a failed ask is visible instead of lost. */
    public function request(Customer $customer, string $platform, string $rawId, ?string $pageId = null): AdAccountLink
    {
        $id = self::normalise($platform, $rawId);
        $link = AdAccountLink::firstOrNew(['customer_id' => $customer->id, 'platform' => $platform, 'external_id' => $id]);
        $fields = ['status' => 'pending', 'requested_at' => now(), 'error' => null];
        if ($platform === 'meta' && $pageId !== null) {
            $digits = preg_replace('~\D~', '', $pageId) ?? '';
            $fields['page_id'] = $digits === '' ? null : $digits;
        }
        $link->fill($fields)->save();

        if ($platform === 'google') {
            $google = $this->registry->for('google');
            try {
                $link->manager_link_id = $google instanceof GoogleAdsPublisher ? $google->requestClientLink($id) : null;
                $link->save();
            } catch (\Throwable $e) {
                // Not the customer's problem and not a dead end: the steps beside the field ask
                // them to add our address on their own account, which needs no link at all. The
                // reason belongs in the log, where we can read it, and not in their settings.
                Log::warning('ads: google link request refused', ['customer' => $customer->id, 'why' => $e->getMessage()]);
            }
        }

        return $this->refresh($link);
    }

    /**
     * Asks the platform what the link is now. The only place a link becomes active: our own
     * request succeeding says nothing about whether the customer pressed accept.
     */
    public function refresh(AdAccountLink $link): AdAccountLink
    {
        $publisher = $this->registry->for($link->platform);
        if (! $publisher->isConfigured()) {
            $link->update(['checked_at' => now(), 'error' => ucfirst($link->platform).' is not connected on our side yet.']);

            return $link;
        }

        try {
            [$status, $name] = $link->platform === 'google'
                ? $this->google($link, $publisher)
                : $this->meta($link, $publisher);
        } catch (\Throwable $e) {
            $link->update(['checked_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return $link;
        }

        $link->update([
            'status' => $status,
            'name' => $name ?? $link->name,
            'checked_at' => now(),
            'error' => null,
            'activated_at' => $status === 'active' ? ($link->activated_at ?? now()) : null,
        ]);

        return $link;
    }

    /** @return array{0:string,1:?string} */
    private function google(AdAccountLink $link, Publisher $publisher): array
    {
        if (! $publisher instanceof GoogleAdsPublisher) {
            return [$link->status, null];
        }
        // The direct grant first. If we can read their account signed in as the account itself,
        // somebody put our address in its user list and we are in, whatever any link says.
        $name = $publisher->accountName($link->external_id);
        if ($name !== null) {
            return ['active', $name];
        }

        // Then the manager link, which only exists when the request could be sent at all.
        try {
            $status = $publisher->clientLinkStatus($link->external_id) ?? 'pending';
        } catch (\Throwable) {
            return ['pending', null];
        }

        return [$status, $status === 'active' ? $publisher->accountName($link->external_id, $publisher->managerId()) : null];
    }

    /** @return array{0:string,1:?string} */
    private function meta(AdAccountLink $link, Publisher $publisher): array
    {
        if (! $publisher instanceof MetaAdsPublisher) {
            return [$link->status, null];
        }
        $name = $publisher->accountName($link->external_id);
        if ($name !== null && (string) $link->page_id !== '') {
            // A Meta ad is published by a page. Reading it now is the difference between finding
            // out here and finding out when a campaign fails.
            $link->page_name = $publisher->pageName((string) $link->page_id);
        }

        return [$name === null ? 'pending' : 'active', $name];
    }

    /** 1234567890 as 123-456-7890, the way Google Ads prints it on screen. */
    public static function dashed(string $id): string
    {
        return strlen($id) === 10 ? substr($id, 0, 3).'-'.substr($id, 3, 3).'-'.substr($id, 6) : $id;
    }
}
