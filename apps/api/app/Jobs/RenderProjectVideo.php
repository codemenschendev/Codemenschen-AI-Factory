<?php

namespace App\Jobs;

use App\Domain\Ai\ImageService;
use App\Domain\Ai\VideoScriptWriter;
use App\Models\ProjectVideo;
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
class RenderProjectVideo implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;      // images are paid for; never silently render twice

    public function __construct(public int $videoId) {}

    public function handle(VideoScriptWriter $writer, ImageService $images): void
    {
        $video = ProjectVideo::find($this->videoId);
        if (! $video || $video->status === 'ready') {
            return;
        }

        $video->update(['status' => 'rendering', 'error' => null]);

        try {
            $this->render($video, $writer, $images);
        } catch (Throwable $e) {
            Log::error('render video failed', ['video' => $video->id, 'error' => $e->getMessage()]);
            $video->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    private function render(ProjectVideo $video, VideoScriptWriter $writer, ImageService $images): void
    {
        $work = rtrim((string) config('services.media.uploads_path'), '/').'/jobs/'.$video->id;
        if (! is_dir($work) && ! mkdir($work, 0775, true) && ! is_dir($work)) {
            throw new \RuntimeException('Không tạo được thư mục làm việc: '.$work);
        }

        $spec = (array) ($video->spec ?? []);
        $size = (string) ($spec['size'] ?? '1080x1920');
        $scenes = $spec['scenes'] ?? [];

        // AI source: the prompt has not been turned into scenes yet.
        if ($video->source === 'ai' && ! $scenes) {
            $scenes = $writer->write((string) $video->prompt, (string) ($spec['language'] ?? 'de'));
        }

        foreach ($scenes as $i => &$scene) {
            $prompt = trim((string) ($scene['image_prompt'] ?? ''));
            // Closing scenes carry no image on purpose: make-video.py paints them on the
            // background colour, which also saves one paid render per clip.
            if ($prompt === '' || isset($scene['image'])) {
                continue;
            }
            $file = sprintf('%02d.png', $i);
            file_put_contents($work.'/'.$file, $images->generate($prompt, $this->imageSize($size)));
            $scene['image'] = $file;
        }
        unset($scene);

        $spec['size'] = $size;
        $spec['scenes'] = array_values($scenes);
        $video->update(['spec' => $spec]);

        file_put_contents($work.'/spec.json', json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $out = $work.'/out.mp4';
        $proc = new Process(['python3', base_path('tools/make-video.py'), $work.'/spec.json', $out], null, null, null, 1500);
        $proc->run();

        if (! $proc->isSuccessful() || ! is_file($out)) {
            throw new \RuntimeException('ffmpeg: '.mb_substr($proc->getErrorOutput() ?: $proc->getOutput(), -400));
        }

        $name = 'p'.$video->project_id.'-'.$video->id.'.mp4';
        $dest = rtrim((string) config('services.media.videos_path'), '/').'/'.$name;
        if (! rename($out, $dest) && ! copy($out, $dest)) {
            throw new \RuntimeException('Không chuyển được file vào thư mục media.');
        }

        $video->update([
            'path' => $name,
            'bytes' => filesize($dest) ?: 0,
            'status' => 'ready',
        ]);
    }

    /** The sidecar only accepts sizes OpenAI supports, so map the canvas onto the nearest one. */
    private function imageSize(string $canvas): string
    {
        [$w, $h] = array_map('intval', explode('x', $canvas) + [1080, 1920]);

        return $w === $h ? '1024x1024' : ($w > $h ? '1536x1024' : '1024x1536');
    }
}
