<?php

namespace App\Domain\Ai;

use Symfony\Component\Process\Process;

/**
 * Screenshots the customer's real page with the chromium that ships in this image.
 *
 * A picture of the actual product beats a generated stock photo for a web ad, and it costs no
 * API call. Only URLs that SiteBrief has already fetched are passed in here, so the public-address
 * check has run; chromium does follow redirects on its own, which that check cannot see, so the
 * capture stays best-effort and its output never replaces the paid pipeline, only a scene of it.
 */
class ScreenshotService
{
    public function capture(string $url, string $dest): bool
    {
        $bin = null;
        foreach (['/usr/bin/chromium-browser', '/usr/bin/chromium'] as $candidate) {
            if (is_executable($candidate)) {
                $bin = $candidate;
                break;
            }
        }
        if ($bin === null) {
            return false;
        }

        $proc = new Process([
            $bin,
            '--headless',
            '--no-sandbox',              // the container runs as root; chromium refuses otherwise
            '--disable-gpu',
            '--disable-dev-shm-usage',   // /dev/shm is 64 MB in docker; renderer crashes without this
            '--hide-scrollbars',
            '--window-size=1440,900',
            '--virtual-time-budget=8000', // let a JS-heavy page finish painting before the shot
            '--screenshot='.$dest,
            $url,
        ], null, null, null, 40);
        $proc->run();

        return $proc->isSuccessful() && is_file($dest) && filesize($dest) > 10_000;
    }
}
