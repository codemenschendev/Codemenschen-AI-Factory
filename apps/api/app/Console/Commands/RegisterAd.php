<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectAd;
use Illuminate\Console\Command;

/**
 * Register a rendered clip against a project so the portal can serve it.
 *
 * ops/video.sh --keep drops the file into the media directory on the host and then calls this
 * inside the api container. The file itself is never moved here: the command only records where
 * it already is, and refuses paths that are not inside the media directory.
 */
class RegisterAd extends Command
{
    protected $signature = 'ad:register
                            {project : Project UUID}
                            {file : File name inside the media directory, e.g. promo-20260901.mp4}
                            {--name= : Label shown in the portal (defaults to the file name)}';

    protected $description = 'Register a rendered ad file against a project';

    public function handle(): int
    {
        $project = Project::find($this->argument('project'));
        if (! $project) {
            $this->error('Không có project '.$this->argument('project'));

            return self::FAILURE;
        }

        $rel = ltrim((string) $this->argument('file'), '/');
        $base = rtrim((string) config('services.media.videos_path'), '/');
        $real = realpath($base.'/'.$rel);
        $realBase = realpath($base);

        if (! $real || ! $realBase || ! str_starts_with($real, $realBase.'/') || ! is_file($real)) {
            $this->error('Không thấy file trong thư mục media: '.$base.'/'.$rel);

            return self::FAILURE;
        }

        $video = ProjectAd::updateOrCreate(
            ['path' => $rel],
            [
                'project_id' => $project->id,
                'name' => $this->option('name') ?: basename($rel),
                'bytes' => filesize($real) ?: 0,
            ],
        );

        $this->info(sprintf('OK #%d · %s → %s', $video->id, $video->name, $project->name));

        return self::SUCCESS;
    }
}
