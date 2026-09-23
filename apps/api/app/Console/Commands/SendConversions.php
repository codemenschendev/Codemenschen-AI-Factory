<?php

namespace App\Console\Commands;

use App\Domain\Ads\Conversions;
use App\Models\AdConversion;
use Illuminate\Console\Command;

/**
 * Sends what is still waiting: a platform that was not set up when the result happened, or one
 * that refused for a reason worth another try. Rows past the platform's window expire here.
 */
class SendConversions extends Command
{
    protected $signature = 'factory:conversions';

    protected $description = 'Send pending conversion reports to Meta and Google';

    public function handle(Conversions $conversions): int
    {
        $rows = AdConversion::where('status', 'pending')->orderBy('id')->limit(200)->get();
        foreach ($rows as $row) {
            $conversions->send($row);
        }
        $this->info($rows->count().' looked at, '.AdConversion::whereIn('id', $rows->pluck('id'))->where('status', 'sent')->count().' sent.');

        return self::SUCCESS;
    }
}
