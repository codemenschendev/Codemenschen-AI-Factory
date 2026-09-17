<?php

namespace App\Jobs;

use App\Domain\Ads\AdFormats;
use App\Domain\Ai\AdScriptWriter;
use App\Domain\Ai\DesignStudy;
use App\Domain\Ai\ImageService;
use App\Domain\Ai\ProductPage;
use App\Domain\Ai\ScreenshotService;
use App\Domain\Ai\SiteBrief;
use App\Domain\Ai\StockPhotos;
use App\Domain\Design\DesignLibrary;
use App\Domain\Library\ImageLibrary;
use App\Models\ProjectAd;
use App\Models\StoreAsset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Renders one clip: prompt -> scene script -> images -> ffmpeg.
 *
 * Runs on the queue because a render is minutes, not milliseconds, and because generating images
 * costs money per scene: a failed HTTP request should not let the customer's browser retry it by
 * refreshing. The row carries the state the portal polls.
 */
class RenderProjectAd implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;      // images are paid for; never silently render twice

    public function __construct(public int $adId) {}

    public function handle(AdScriptWriter $writer, ImageService $images, SiteBrief $sites,
        ScreenshotService $shots, ImageLibrary $library, DesignLibrary $refs, StockPhotos $stock,
        ProductPage $pages, DesignStudy $study): void
    {
        $ad = ProjectAd::find($this->adId);
        if (! $ad || $ad->status === 'ready') {
            return;
        }

        $ad->update(['status' => 'rendering', 'error' => null]);

        try {
            $this->render($ad, $writer, $images, $sites, $shots, $library, $refs, $stock, $pages, $study);
        } catch (Throwable $e) {
            Log::error('render video failed', ['ad' => $ad->id, 'error' => $e->getMessage()]);
            $ad->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    private function render(ProjectAd $ad, AdScriptWriter $writer, ImageService $images, SiteBrief $sites,
        ScreenshotService $shots, ImageLibrary $library, DesignLibrary $refs, StockPhotos $stock,
        ProductPage $pages, DesignStudy $study): void
    {
        $work = rtrim((string) config('services.media.uploads_path'), '/').'/jobs/'.$ad->id;
        if (! is_dir($work) && ! mkdir($work, 0775, true) && ! is_dir($work)) {
            throw new \RuntimeException('Could not create work directory: '.$work);
        }

        $spec = (array) ($ad->spec ?? []);
        $size = (string) ($spec['size'] ?? '1080x1920');
        $scenes = $spec['scenes'] ?? [];

        // Fetch the named page once; both the copy and the screenshot below build on it. The
        // customer's background choice decides whether the screenshot is used: 'photo' skips it.
        $wantShot = ($spec['background'] ?? 'auto') !== 'photo';
        $site = $wantShot ? $sites->forPrompt((string) $ad->prompt) : null;

        // AI source: the prompt has not been turned into scenes yet. Copy is grounded on the
        // page even when its screenshot is not wanted, so fetch it for context if we skipped it.
        if ($ad->source === 'ai' && ! $scenes) {
            $forCopy = $site ?? $sites->forPrompt((string) $ad->prompt);
            $scenes = $writer->write(
                (string) $ad->prompt,
                (string) ($spec['language'] ?? 'de'),
                $ad->kind,
                $this->context($ad, $forCopy, $pages, $study),
                isset($spec['goal']) ? (string) $spec['goal'] : null,
                isset($spec['angle']) ? (string) $spec['angle'] : null,
                // A real ad of the same angle, if the library has one. The angle is the request:
                // showing a testimonial when the customer asked for a price anchor would teach
                // the wrong shape, so a miss sends no picture at all.
                $refs->adReference(
                    isset($spec['angle']) ? (string) $spec['angle'] : null,
                    (string) $ad->prompt,
                    AdFormats::shape(isset($spec['format']) ? (string) $spec['format'] : null),
                ),
            );
            // Kept on the ad: a flaw the second attempt could not remove is the operator's to see.
            $spec['copy_faults'] = $writer->faults;
        }

        // An image ad is one picture. Anything the model sent beyond the first scene would be
        // paid for and then thrown away by the renderer.
        if ($ad->kind === 'image') {
            $scenes = array_slice($scenes, 0, 1);
        }

        // If the brief named a real page, a screenshot of it becomes the first scene, framed on
        // the brand colour. That is a truer web ad than a generated photo, and it means one fewer
        // paid image. Best effort: any failure just leaves the AI picture in place.
        if ($site && $scenes) {
            $shot = $work.'/site.png';
            if ($shots->capture($site['url'], $shot)) {
                $scenes[0]['image'] = 'site.png';
                $scenes[0]['inset'] = true;
                if (! empty($site['brand_color'])) {
                    $spec['bg'] = $site['brand_color'];
                }
            }
        }

        // Whose photographs ended up in this ad. Pexels asks for a credit where their API is
        // used, and an ad is published: the operator needs to be able to answer for every frame.
        $credits = [];

        foreach ($scenes as $i => &$scene) {
            $prompt = trim((string) ($scene['picture'] ?? $scene['image_prompt'] ?? ''));
            // Closing scenes carry no image on purpose: make-ad.py paints them on the
            // background colour, which also saves one paid render per clip.
            if ($prompt === '' || isset($scene['image'])) {
                continue;
            }
            // Ask the library before paying for a picture. A hit costs nothing and returns at
            // once; a miss is generated and then filed, so the next ad that needs this scene
            // finds it. Photos stay with the project they were made for until somebody marks
            // one shared, which keeps two rival businesses off the same hero image.
            $hit = $library->find($prompt, (string) $ad->project_id);
            // path() is asked separately and can come back empty: the operator may have deleted
            // the photo between the search and here. Then this falls through and generates one.
            $reuse = $hit ? $library->path($hit['id']) : null;
            if ($reuse) {
                $file = sprintf('%02d.jpg', $i);
                copy($reuse, $work.'/'.$file);
                Log::info('ad image reused', ['ad' => $ad->id, 'asset' => $hit['id'], 'score' => round($hit['score'], 2)]);
            } elseif ($shot = $stock->findForAd($prompt)) {
                // A free photograph of a real place beats a generated one and costs nothing.
                // findForAd refuses any scene that names a person: the Pexels licence allows
                // commercial use but guarantees no model release, and a stranger's face in a paid
                // ad for somebody's salon is the endorsement its terms ask us not to imply.
                $file = sprintf('%02d.jpg', $i);
                file_put_contents($work.'/'.$file, $shot['bytes']);
                $library->remember($work.'/'.$file, $prompt, (string) $ad->project_id);
                $credits[] = $shot['credit'].' · '.$shot['url'];
                Log::info('ad image from stock', ['ad' => $ad->id, 'credit' => $shot['credit']]);
            } else {
                $file = sprintf('%02d.png', $i);
                file_put_contents($work.'/'.$file, $images->generate($prompt, $this->imageSize($size)));
                $library->remember($work.'/'.$file, $prompt, (string) $ad->project_id);
            }
            $scene['image'] = $file;
        }
        unset($scene);

        $spec['size'] = $size;
        $spec['kind'] = $ad->kind;
        $spec['scenes'] = array_values($scenes);
        // Kept on the ad, not painted into the frame: an ad has no room for a credit line, and
        // the operator is the one who has to be able to answer for a photograph.
        $spec['photo_credits'] = $credits;
        $ad->update(['spec' => $spec]);

        file_put_contents($work.'/spec.json', json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $ext = $ad->kind === 'image' ? 'png' : 'mp4';
        $out = $work.'/out.'.$ext;
        $proc = new Process(['python3', base_path('tools/make-ad.py'), $work.'/spec.json', $out], null, null, null, 1500);
        $proc->run();

        if (! $proc->isSuccessful() || ! is_file($out)) {
            throw new \RuntimeException('render: '.mb_substr($proc->getErrorOutput() ?: $proc->getOutput(), -400));
        }

        $name = 'p'.$ad->project_id.'-'.$ad->id.'.'.$ext;
        $dest = rtrim((string) config('services.media.videos_path'), '/').'/'.$name;
        if (! rename($out, $dest) && ! copy($out, $dest)) {
            throw new \RuntimeException('Could not move file into the media directory.');
        }

        $ad->update([
            'path' => $name,
            'bytes' => filesize($dest) ?: 0,
            'status' => 'ready',
        ]);
    }

    /**
     * What the ad is actually about. Without this the copywriter only sees the customer's one
     * sentence: ask it for "an ad for codemenschen.at" and it writes something that would fit any
     * software company, because nothing told it what that is.
     *
     * @return array<string,string>
     */
    /** @param  array<string,string>|null  $site */
    private function context(ProjectAd $ad, ?array $site, ?ProductPage $pages = null, ?DesignStudy $study = null): array
    {
        $project = $ad->project;

        // The whole homepage and a product brief written from it. Title, description and two
        // headings were what the copywriter had before, and for wp-giftcard.com the same thin
        // reading produced ads for cashing in gift cards. The brief says what is sold, to whom,
        // why, and what the site itself proves.
        $domain = $pages !== null ? ProductPage::domainIn((string) $ad->prompt) : null;
        $page = $domain !== null ? $pages->read($domain) : null;
        $product = $page !== null ? $study?->product((string) $ad->prompt, $page) : null;

        // When the brief names a page, THAT is what the ad is for. The project is only where the
        // ad is filed: asking for an ad for codemenschen.at while sitting in a hair salon project
        // used to produce an ad for the hair salon, because the project came first in the list.
        if ($site) {
            $context = ['subject' => $site['url']];
            foreach (['title', 'description', 'headings', 'brand_color'] as $k) {
                if (isset($site[$k])) {
                    $context['subject_'.$k] = $site[$k];
                }
            }
            if ($product !== null) {
                $context['subject_product_brief'] = $product;
            }
            if ($page !== null) {
                $context['subject_website_text'] = mb_substr($page['text'], 0, 2500);
            }
            $context['filed_under_project'] = mb_substr((string) $project->name, 0, 120);

            return $context;
        }
        if ($page !== null) {
            // The page answered here but not to SiteBrief (a second redirect, a slow host): still
            // the subject, still better than the project name.
            return array_filter([
                'subject' => $page['url'],
                'subject_product_brief' => (string) $product,
                'subject_website_text' => mb_substr($page['text'], 0, 2500),
                'filed_under_project' => mb_substr((string) $project->name, 0, 120),
            ]);
        }

        // No page: the ad is for the app this project builds. The project name is the first sixty
        // characters of the idea, and "expo" told the copywriter nothing, so the whole idea and
        // the store description (once the assets stage has written one) go instead.
        $store = StoreAsset::where('project_id', $project->id)->where('kind', 'description')
            ->orderByRaw('locale = ? desc', [(string) (($ad->spec ?? [])['language'] ?? 'de')])->latest('version')->value('content');

        return array_filter([
            'subject' => (string) $project->name,
            'subject_description' => mb_substr((string) $project->order?->quote?->idea, 0, 1500),
            'subject_store_description' => mb_substr((string) $store, 0, 1500),
            'subject_platform' => $project->stack === 'nextjs' ? 'web app' : 'mobile app',
        ]);
    }

    /** The sidecar only accepts sizes OpenAI supports, so map the canvas onto the nearest one. */
    private function imageSize(string $canvas): string
    {
        [$w, $h] = array_map('intval', explode('x', $canvas) + [1080, 1920]);

        return $w === $h ? '1024x1024' : ($w > $h ? '1536x1024' : '1024x1536');
    }

    /** A worker timeout throws MaxAttemptsExceeded OUTSIDE handle(), so record it here or the row
        stays stuck in its in-progress state forever. */
    public function failed(Throwable $e): void
    {
        ProjectAd::whereKey($this->adId)->where('status', '!=', 'ready')
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
    }
}
