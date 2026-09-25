<?php

namespace App\Domain\Sites;

use App\Http\Controllers\LandingController;
use App\Jobs\BuildSiteFromMockup;
use App\Models\Order;
use App\Models\Project;
use App\Models\Prototype;
use App\Services\Notify;

/**
 * A website somebody bought (2026-09-24). The product is the site preview itself: paid for, it
 * stops expiring, goes live at its address and, when the customer asks, on their own domain.
 *
 * Nothing is built again. The page the customer saw and liked is the page that goes live; the
 * free change of the preview stays theirs, and further changes are done by us on request.
 * The one exception is a preview that is a design picture (the Codex switch): it is built into
 * the page after payment (BuildSiteFromMockup) and goes live once that is done.
 */
class SiteService
{
    /** The preview becomes the project's page: kept for good, owned by the buyer. */
    public function claim(Project $project, Prototype $prototype, Order $order): void
    {
        $prototype->update([
            'project_id' => $project->id,
            'customer_id' => $prototype->customer_id ?? $order->customer_id,
            // Bought pages never expire; the daily cleanup only drops pages without a project.
            'expires_at' => null,
        ]);
        if (MockupSite::isMockup($prototype)) {
            // After the payment's transaction, so the job finds the project it belongs to.
            BuildSiteFromMockup::dispatch($prototype->id)->afterCommit();
        }
    }

    /** Switches the page on. Called at payment, or by pipeline:tick once the withdrawal period is over. */
    public function goLive(Project $project): void
    {
        $prototype = $project->prototype;
        if ($prototype === null) {
            $project->update(['status' => 'FAILED', 'failed_reason' => 'The bought preview is gone.']);
            app(Notify::class)->note($project, 'website could not go live: the preview is gone');

            return;
        }
        // A design picture is not a website yet: it goes live when BuildSiteFromMockup is done.
        if (MockupSite::isMockup($prototype)) {
            return;
        }
        $prototype->update(['published_at' => $prototype->published_at ?? now()]);
        $project->update(['status' => 'PUBLISHED']);
        $url = LandingController::url($prototype);
        $project->recordEvent('site.live', ['url' => $url]);
        app(Notify::class)->note($project, "website live at {$url}");
    }

    public static function url(Project $project): ?string
    {
        $prototype = $project->prototype;

        return $prototype !== null && $prototype->published_at !== null ? LandingController::url($prototype) : null;
    }

    /**
     * The customer's domain, as a host name: scheme, path and a leading www. are dropped. Null
     * when the field was emptied, false when it is not a domain.
     */
    public static function normalizeDomain(string $input): string|null|false
    {
        $v = strtolower(trim($input));
        if ($v === '') {
            return null;
        }
        $v = preg_replace('~^[a-z]+://~', '', $v);
        $v = explode('/', $v, 2)[0];
        $v = preg_replace('~^www\.~', '', $v);
        $ok = strlen($v) <= 253 && preg_match('~^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$~', $v) === 1;

        return $ok ? $v : false;
    }

    /** Notes the wish; a person connects the domain (vhost and certificate, see infra/DEPLOY.md). */
    public function requestDomain(Project $project, ?string $domain): void
    {
        $project->update(['domain' => $domain, 'domain_requested_at' => $domain === null ? null : now()]);
        $project->recordEvent('site.domain_requested', ['domain' => $domain]);
        if ($domain !== null) {
            app(Notify::class)->note($project, "wants the domain {$domain}: connect it (vhost and certificate, infra/DEPLOY.md)");
        }
    }

    /** The address a visitor's DNS has to point at. */
    public static function serverIp(): string
    {
        return (string) config('services.sites.server_ip');
    }
}
