<?php

namespace App\Jobs;

use App\Domain\Ads\Conversions;
use App\Models\AdConversion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends one conversion row. The row carries the outcome; factory:conversions picks up the rest. */
class SendAdConversion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $conversionId) {}

    public function handle(Conversions $conversions): void
    {
        if ($row = AdConversion::find($this->conversionId)) {
            $conversions->send($row);
        }
    }
}
