<?php

namespace App\Jobs;

use App\Domain\Ai\ImageService;
use App\Domain\Library\ImageLibrary;
use App\Domain\Ai\ScreenshotService;
use App\Domain\Ai\SiteBrief;
use App\Domain\Ai\AdScriptWriter;
use App\Models\ProjectAd;
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

    public function handle(AdScriptWriter $writer, ImageService $images, SiteBrief $sites, ScreenshotService $shots, ImageLibrary $library): void
    {
        $ad = ProjectAd::find($this->adId);
        if (! $ad || $ad->status === 'ready') {
            return;
        }

        $ad->update(['status' => 'rendering', 'error' => null]);

        try {
            $this->render($ad, $writer, $images, $sites, $shots, $library);
        } catch (Throwable $e) {
            Log::error('render video failed', ['ad' => $ad->id, 'error' => $e->getMessage()]);
            $ad->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    private function render(ProjectAd $ad, AdScriptWriter $writer, ImageService $images, SiteBrief $sites, ScreenshotService $shots, ImageLibrary $library): void
    {
        $work = rtrim((string) config('services.media.uploads_path'), '/').'/jobs/'.$ad->id;
        if (! is_dir($work) && ! mkdir($work, 0775, true) && ! is_dir($work)) {
            throw new \RuntimeException('Không tạo được thư mục làm việc: '.$work);
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
                $this->context($ad, $forCopy),
                isset($spec['goal']) ? (string) $spec['goal'] : null,
            );
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
            throw new \RuntimeException('Không chuyển được file vào thư mục media.');
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
    private function context(ProjectAd $ad, ?array $site): array
    {
        $project = $ad->project;

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
            $context['filed_under_project'] = mb_substr((string) $project->name, 0, 120);

            return $context;
        }

        return array_filter([
            'subject' => (string) $project->name,
            'subject_platform' => (string) $project->stack,
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
    public function failed(\Throwable $e): void
    {
        \App\Models\ProjectAd::whereKey($this->adId)->where('status', '!=', 'ready')
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
    }
}
