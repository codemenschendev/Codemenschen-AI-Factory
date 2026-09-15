<?php

namespace App\Services;

use App\Models\ChangeMessage;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Screenshots a customer attaches in the change chat. "This button" with a picture beats three
 * questions about which button.
 *
 * The portal shrinks each picture before sending (longest side 1600 px), so what arrives is a
 * few hundred kilobytes. Files live next to the other customer uploads, one folder per project,
 * and the message meta keeps only ids. The assistant gets them inline with the conversation, the
 * revise agent as files next to the repository.
 */
class ChangeShots
{
    public const MAX_PER_MESSAGE = 3;

    public const MAX_BYTES = 4 * 1024 * 1024;

    /** Pictures the assistant sees at once: the newest of the draft. */
    public const MAX_FOR_ASSISTANT = 4;

    /** Pictures the revise agent gets for one round. */
    public const MAX_FOR_ROUND = 6;

    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /**
     * Decodes data URIs and stores them. Anything that is not a real PNG, JPEG or WebP under the
     * size limit is refused as a whole, so a customer never sends half of what they attached.
     *
     * @param  list<string>  $dataUris
     * @return list<array{id:string, mime:string, bytes:int}>
     */
    public function store(Project $project, array $dataUris): array
    {
        $decoded = [];
        foreach (array_slice($dataUris, 0, self::MAX_PER_MESSAGE) as $uri) {
            abort_unless(is_string($uri) && preg_match('#^data:image/[a-z]+;base64,#', $uri), 422, 'Unsupported image.');
            $bytes = base64_decode(substr($uri, strpos($uri, ',') + 1), true);
            abort_if($bytes === false || $bytes === '', 422, 'Unsupported image.');
            abort_if(strlen($bytes) > self::MAX_BYTES, 422, 'Image too large.');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            abort_unless(isset(self::TYPES[$mime]), 422, 'Unsupported image.');
            $decoded[] = [$bytes, $mime];
        }

        $dir = $this->dir($project);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stored = [];
        foreach ($decoded as [$bytes, $mime]) {
            $id = (string) Str::uuid();
            file_put_contents($dir.'/'.$id.'.'.self::TYPES[$mime], $bytes);
            $stored[] = ['id' => $id, 'mime' => $mime, 'bytes' => strlen($bytes)];
        }

        return $stored;
    }

    /** Absolute path of one stored picture of a message, or null when it is gone. */
    public function path(ChangeMessage $message, int $n): ?string
    {
        $image = ($message->meta['images'] ?? [])[$n] ?? null;
        if (! is_array($image) || ! Str::isUuid($image['id'] ?? '') || ! isset(self::TYPES[$image['mime'] ?? ''])) {
            return null;
        }
        $path = $this->dir($message->project).'/'.$image['id'].'.'.self::TYPES[$image['mime']];

        return is_file($path) ? $path : null;
    }

    /**
     * The pictures of these messages as {mime, data} for the worker, newest last, at most $limit.
     *
     * @param  iterable<ChangeMessage>  $messages
     * @return list<array{message_id:int, mime:string, data:string}>
     */
    public function inline(iterable $messages, int $limit): array
    {
        $out = [];
        foreach ($messages as $message) {
            foreach (array_keys($message->meta['images'] ?? []) as $n) {
                $path = $this->path($message, $n);
                if ($path !== null) {
                    $out[] = ['message_id' => $message->id, 'mime' => $message->meta['images'][$n]['mime'], 'data' => base64_encode((string) file_get_contents($path))];
                }
            }
        }

        return array_slice($out, -$limit);
    }

    private function dir(Project $project): string
    {
        return rtrim((string) config('services.media.uploads_path'), '/').'/change-shots/'.$project->id;
    }
}
