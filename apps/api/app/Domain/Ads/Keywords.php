<?php

namespace App\Domain\Ads;

use App\Domain\Ai\KeywordWriter;
use App\Models\CampaignKeyword;
use App\Models\MarketingCampaign;
use RuntimeException;

/**
 * The words a search campaign is bought for, from proposal to live (2026-09-23).
 *
 * The order is deliberate and it is the whole point: Appwerk AI proposes, a person approves, and
 * only then does anything reach Google. A proposal that nobody ticked is a row in a table and
 * costs nothing. This class never approves on its own.
 */
class Keywords
{
    public function __construct(
        private readonly KeywordWriter $writer,
        private readonly PublisherRegistry $registry,
    ) {}

    /**
     * Asks for a list and writes it down as proposals.
     *
     * Existing rows are left exactly as they are. An admin who already approved "roof repair
     * vienna" does not get it proposed back at them, and a word they removed stays removed.
     *
     * @return array{proposed:int,skipped:int}
     */
    public function propose(MarketingCampaign $campaign): array
    {
        $out = $this->writer->propose($campaign, self::language($campaign));
        $proposed = 0;
        $skipped = 0;

        foreach ($out['keywords'] as $row) {
            $made = $this->remember($campaign, $row['text'], $row['match'], false);
            $made ? $proposed++ : $skipped++;
        }
        foreach ($out['negatives'] as $text) {
            $made = $this->remember($campaign, $text, 'phrase', true);
            $made ? $proposed++ : $skipped++;
        }

        return ['proposed' => $proposed, 'skipped' => $skipped];
    }

    /** True when the row is new. A word we already know keeps whatever an admin decided about it. */
    private function remember(MarketingCampaign $campaign, string $text, string $match, bool $negative): bool
    {
        $exists = CampaignKeyword::where('campaign_id', $campaign->id)
            ->where('text', $text)->where('negative', $negative)->exists();
        if ($exists) {
            return false;
        }

        CampaignKeyword::create([
            'campaign_id' => $campaign->id, 'text' => $text, 'match_type' => $match,
            'negative' => $negative, 'status' => 'proposed', 'source' => 'ai',
        ]);

        return true;
    }

    /**
     * Sends what an admin approved, and withdraws what they paused.
     *
     * A campaign that is not on Google yet needs no call: its keywords travel with it when it is
     * published, in the same batch as the budget and the ad.
     *
     * @return array{applied:int,paused:int,pending:bool}
     */
    public function apply(MarketingCampaign $campaign): array
    {
        if ($campaign->platform !== 'google') {
            throw new RuntimeException('Keywords are a Google search thing. A Meta campaign is targeted by audience, not by search terms.');
        }

        $todo = $campaign->keywords()
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('status', 'approved')->whereNull('resource_name'))
                ->orWhere(fn ($b) => $b->where('status', 'paused')->whereNotNull('resource_name')))
            // The answers come back in the order the operations were sent, so the order has to be
            // one we chose rather than whatever the database felt like.
            ->orderBy('id')
            ->get();

        if (($campaign->platform_ref['ad_group'] ?? null) === null) {
            // Nothing to do and nothing wrong: publishing carries them.
            return ['applied' => 0, 'paused' => 0, 'pending' => $todo->isNotEmpty()];
        }

        $publisher = $this->registry->for('google');
        if (! $publisher instanceof GoogleAdsPublisher) {
            throw new RuntimeException('Google Ads is not connected.');
        }

        return $publisher->applyKeywords($campaign, $todo) + ['pending' => false];
    }

    /** The language the keywords are typed in, which is the language the campaign speaks. */
    private static function language(MarketingCampaign $campaign): string
    {
        $from = $campaign->strategy['language'] ?? $campaign->project?->customer?->locale;

        return in_array($from, ['de', 'en'], true) ? $from : 'de';
    }
}
