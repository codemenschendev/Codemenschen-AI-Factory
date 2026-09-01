<?php

namespace App\Domain\Ads;

use App\Models\MarketingCampaign;

/**
 * One ad platform. Implementations run against Codemenschen's own ad account.
 *
 * The contract has one iron rule: publish() ALWAYS creates the campaign paused. Nothing in this
 * layer starts spending. Spend begins only when a person calls activate(), which is wired to a
 * button, never to an automatic step. The customer funds the spend, so the monthly budget they
 * paid for is set as the platform's own cap inside publish().
 */
interface Publisher
{
    public function key(): string;                 // 'meta' | 'google'

    public function isConfigured(): bool;

    /**
     * Create the campaign on the platform in PAUSED state, upload the creative, wire it up, and
     * return the ids to store in platform_ref. Must never leave it active.
     *
     * @return array<string,mixed>
     */
    public function publish(MarketingCampaign $campaign): array;

    /** Flip an already-published, paused campaign to active. The spend gate; called by a human. */
    public function activate(MarketingCampaign $campaign): void;

    public function pause(MarketingCampaign $campaign): void;
}
