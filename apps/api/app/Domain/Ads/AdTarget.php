<?php

namespace App\Domain\Ads;

use App\Models\AdAccountLink;
use App\Models\MarketingCampaign;
use RuntimeException;

/**
 * Whose ad account a campaign runs on (2026-09-23).
 *
 * The owner decided: once a customer has connected their own account, their campaigns run there.
 * Their card, their invoice, their spending limit. Codemenschen does not front the money and does
 * not carry somebody else's spend on its own account.
 *
 * Appwerk's own campaigns, and anything with no customer behind it, run on our account.
 */
class AdTarget
{
    /**
     * @return array{owner:string,account:string,page:string,login:string}
     *                                                                     owner: client or appwerk. account: act_… or ten digits. page: Meta only.
     *                                                                     login: the Google login-customer-id, our manager when the account is a customer's.
     */
    public static function for(MarketingCampaign $campaign, string $platform): array
    {
        $link = self::link($campaign, $platform);
        if ($link === null) {
            return [
                'owner' => 'appwerk',
                'account' => AdSettings::get($platform === 'meta' ? 'meta_ad_account_id' : 'google_customer_id'),
                'page' => AdSettings::get('meta_page_id'),
                'login' => (string) config('services.ads.google.login_customer_id', ''),
            ];
        }

        if ($platform === 'meta' && (string) $link->page_id === '') {
            throw new RuntimeException('Meta needs the customer\'s Facebook page before an ad can run on their account.');
        }

        return [
            'owner' => 'client',
            'account' => $link->external_id,
            'page' => (string) $link->page_id,
            // A customer's account is reached through our manager account, never through itself.
            'login' => AdSettings::get('google_manager_id'),
        ];
    }

    /** The customer's connected account for this campaign, if they have one and it is live. */
    public static function link(MarketingCampaign $campaign, string $platform): ?AdAccountLink
    {
        $customerId = $campaign->project?->customer_id;
        if ($customerId === null) {
            return null;
        }

        return AdAccountLink::where('customer_id', $customerId)
            ->where('platform', $platform)
            ->where('status', 'active')
            ->first();
    }
}
