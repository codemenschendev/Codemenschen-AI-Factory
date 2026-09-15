<?php

namespace App\Console\Commands;

use App\Services\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Sends a picture with a known text through the worker and the OpenClaw gateway, the same path
 * change chat screenshots take, and alerts #appwerk-alerts when the model cannot read it.
 *
 * On 2026-09-15 the gateway accepted screenshots and dropped them without an error, because its
 * chat backend had no imageArg. The assistant then asked customers what their screenshot showed.
 * An OpenClaw update or a rewritten config can bring that back without anyone noticing.
 */
class VisionCheck extends Command
{
    protected $signature = 'factory:vision-check';

    protected $description = 'Check that the change chat assistant can still see screenshots';

    public function handle(Notify $notify): int
    {
        $image = 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('vision-check.png')));

        try {
            $res = Http::timeout(120)
                ->withToken(config('services.worker.token'))
                ->post(rtrim(config('services.worker.url'), '/').'/vision-check', ['image' => $image]);
        } catch (\Throwable $e) {
            return $this->raise($notify, 'vision check: worker not reachable ('.mb_substr($e->getMessage(), 0, 120).')');
        }
        if (! $res->ok()) {
            return $this->raise($notify, "vision check: worker answered HTTP {$res->status()}");
        }

        $text = (string) $res->json('text', '');
        if (! str_contains(mb_strtoupper($text), 'ZIMTSTERN') || ! str_contains($text, '58')) {
            return $this->raise($notify, 'vision check: the chat model cannot read screenshots, it answered "'.mb_substr($text, 0, 80).'". Check imageArg of claude-cli-chat in the OpenClaw config.');
        }

        $this->info("ok: {$text}");

        return self::SUCCESS;
    }

    private function raise(Notify $notify, string $line): int
    {
        $notify->system($line);
        $this->error($line);

        return self::FAILURE;
    }
}
