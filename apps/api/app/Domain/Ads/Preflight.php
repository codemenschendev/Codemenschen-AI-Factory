<?php

namespace App\Domain\Ads;

use App\Models\MarketingCampaign;

/**
 * Checks a campaign against a platform's rules before any API call.
 *
 * A rejection from Meta or Google costs a round trip and an opaque error; the same problems are
 * cheap to catch here. This is about shape, not taste: character limits, how many headlines a
 * responsive Google ad needs, whether there is a creative file at all, whether the budget is set.
 *
 * @return array<int,string> The problems found. Empty means clear to publish.
 */
class Preflight
{
    /** @return array<int,string> */
    public function check(MarketingCampaign $campaign): array
    {
        $problems = [];

        if ((int) $campaign->ad_budget_monthly_eur <= 0) {
            $problems[] = 'Ngân sách quảng cáo bằng 0. Khách phải thanh toán ngân sách trước khi đăng.';
        }

        $headlines = $this->creatives($campaign, 'headline');
        $bodies = $this->creatives($campaign, 'ad_copy');

        if ($headlines === []) {
            $problems[] = 'Thiếu headline.';
        }
        if ($bodies === []) {
            $problems[] = 'Thiếu nội dung quảng cáo (ad_copy).';
        }

        if ($campaign->platform === 'meta') {
            foreach ($headlines as $h) {
                if (mb_strlen($h) > 40) {
                    $problems[] = 'Meta: headline quá 40 ký tự — "'.mb_substr($h, 0, 30).'…"';
                }
            }
            if (! $campaign->project_ad_id) {
                $problems[] = 'Meta: cần một creative (ảnh hoặc video) cho quảng cáo.';
            }
        }

        if ($campaign->platform === 'google') {
            // Responsive Search Ads: at least 3 headlines and 2 descriptions, with hard limits.
            if (count($headlines) < 3) {
                $problems[] = 'Google: cần ít nhất 3 headline (RSA), hiện có '.count($headlines).'.';
            }
            if (count($bodies) < 2) {
                $problems[] = 'Google: cần ít nhất 2 description (RSA), hiện có '.count($bodies).'.';
            }
            foreach ($headlines as $h) {
                if (mb_strlen($h) > 30) {
                    $problems[] = 'Google: headline quá 30 ký tự — "'.mb_substr($h, 0, 25).'…"';
                }
            }
            foreach ($bodies as $b) {
                if (mb_strlen($b) > 90) {
                    $problems[] = 'Google: description quá 90 ký tự — "'.mb_substr($b, 0, 25).'…"';
                }
            }
        }

        return $problems;
    }

    /** @return array<int,string> */
    private function creatives(MarketingCampaign $campaign, string $kind): array
    {
        return $campaign->creatives
            ->where('kind', $kind)
            ->pluck('content')
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->values()
            ->all();
    }
}
