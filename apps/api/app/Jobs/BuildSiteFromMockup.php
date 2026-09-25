<?php

namespace App\Jobs;

use App\Domain\Sites\MockupSite;
use App\Domain\Sites\SiteService;
use App\Models\Prototype;
use App\Services\Notify;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A bought prototype that is a design picture becomes the website (MockupSite), and then goes live
 * if its withdrawal period allows. Twice at most: a lost gateway or an empty render quota is worth
 * one more try ten minutes later; after that the operator is told and builds it by hand.
 */
class BuildSiteFromMockup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 2;

    public int $backoff = 600;

    public function __construct(public string $prototypeId) {}

    public function handle(MockupSite $site, SiteService $sites): void
    {
        $proto = Prototype::find($this->prototypeId);
        if ($proto === null || ! MockupSite::isMockup($proto)) {
            return;
        }
        $out = $site->build($proto);
        $proto->update(['html' => $out['html'], 'qa' => $out['qa']]);

        $project = $proto->project;
        if ($project !== null) {
            $project->recordEvent('site.built', ['from' => 'mockup', 'shots' => $out['qa']['from_mockup']['shots']]);
            // Due already (waiver given, or the period is over): the page goes live now. Otherwise
            // pipeline:tick switches it on when the period ends, as for any bought site.
            if ($project->status === 'PAID' && ! $project->build_starts_at?->isFuture()) {
                $sites->goLive($project->fresh());
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('site from mockup failed', ['id' => $this->prototypeId, 'error' => $e->getMessage()]);
        $project = Prototype::find($this->prototypeId)?->project;
        if ($project !== null) {
            app(Notify::class)->note($project, 'website could not be built from the design picture: '.mb_substr($e->getMessage(), 0, 200));
        }
    }
}
