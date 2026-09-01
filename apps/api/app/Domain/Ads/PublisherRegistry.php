<?php

namespace App\Domain\Ads;

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
            default => throw new RuntimeException("Nền tảng không hỗ trợ: {$platform}"),
        };
    }

    /** @return array<string,bool> Which platforms have credentials right now. */
    public function configured(): array
    {
        return ['meta' => $this->meta->isConfigured(), 'google' => $this->google->isConfigured()];
    }
}
