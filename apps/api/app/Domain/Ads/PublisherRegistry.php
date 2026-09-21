<?php

namespace App\Domain\Ads;

use App\Models\Setting;
use RuntimeException;

/** Picks the publisher for a campaign's platform. */
class PublisherRegistry
{
    public function __construct(
        private MetaAdsPublisher $meta,
        private GoogleAdsPublisher $google,
    ) {}

    public function for(string $platform): Publisher
    {
        return match ($platform) {
            'meta' => $this->meta,
            'google' => $this->google,
            default => throw new RuntimeException("Unsupported platform: {$platform}"),
        };
    }

    /** @return array<string,bool> Which platforms have credentials right now. */
    public function configured(): array
    {
        return ['meta' => $this->meta->isConfigured(), 'google' => $this->google->isConfigured()];
    }

    /** Platforms the owner switched off: nothing publishes there and the daily check skips them. */
    public const PAUSED = 'ads.paused';

    /** @return list<string> */
    public static function pausedPlatforms(): array
    {
        $v = Setting::read(self::PAUSED, []);

        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    public static function paused(string $platform): bool
    {
        return in_array($platform, self::pausedPlatforms(), true);
    }

    /** @return list<string> the platforms paused afterwards */
    public static function setPaused(string $platform, bool $paused, ?string $by = null): array
    {
        $now = array_values(array_diff(self::pausedPlatforms(), [$platform]));
        if ($paused) {
            $now[] = $platform;
        }
        Setting::write(self::PAUSED, $now, $by);

        return $now;
    }

    /** Where factory:ads-check leaves its last answer for a platform. */
    public static function verifiedKey(string $platform): string
    {
        return "ads.verified.{$platform}";
    }

    /** @return list<Publisher> */
    public function all(): array
    {
        return [$this->meta, $this->google];
    }

    /**
     * What the operator sees: configured or not, which env names are still empty, and what the last
     * real credential check (factory:ads-check) found. No values and no API calls here; this runs
     * on every admin overview and must stay free. "Configured" alone is not "connected": a token
     * the account never accepted is configured.
     *
     * @return array<string,array{configured:bool,missing:list<string>,verified:?array{ok:bool,at:string,account:?string,detail:?string}}>
     */
    public function status(): array
    {
        $out = [];
        foreach ($this->all() as $p) {
            $out[$p->key()] = [
                'paused' => self::paused($p->key()),
                'configured' => $p->isConfigured(),
                'missing' => $p->missing(),
                'verified' => Setting::read(self::verifiedKey($p->key())),
            ];
        }

        return $out;
    }
}
