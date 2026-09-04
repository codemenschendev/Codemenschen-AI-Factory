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

    /** @return list<Publisher> */
    public function all(): array
    {
        return [$this->meta, $this->google];
    }

    /**
     * What the operator sees: configured or not, and which env names are still empty. No values,
     * no API calls; this runs on every admin overview and must stay free.
     *
     * @return array<string,array{configured:bool,missing:list<string>}>
     */
    public function status(): array
    {
        $out = [];
        foreach ($this->all() as $p) {
            $out[$p->key()] = ['configured' => $p->isConfigured(), 'missing' => $p->missing()];
        }

        return $out;
    }
}
