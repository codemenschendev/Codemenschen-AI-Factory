<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client for the image sidecar that runs as the `openclaw` user on the host.
 *
 * The OpenClaw gateway has no images/generations endpoint — generation is only reachable through
 * the agent's tool or the `openclaw infer image generate` CLI, and this container has no shell as
 * that user. The sidecar wraps the CLI as HTTP; the giftcard and CookCam stacks call the same
 * service. Modelled on Codemenschen_OpenClaw's OpenClawImageGateway.
 *
 * Every call costs real money (Codemenschen's OpenAI account), so `quality` stays at whatever
 * services.ai_image.quality says unless a caller has a reason to raise it.
 */
class ImageService
{
    /** Which backend rendered the last picture: 'codex' or 'openai'. */
    public ?string $lastBackend = null;

    /**
     * @param  list<string>  $refs  raw bytes of the business's own pictures, shown to the image model as the product
     * @return string Raw image bytes.
     */
    public function generate(string $prompt, string $size, array $refs = []): string
    {
        // Paid ads stay on the metered API unless switched on separately: a clip can need a
        // picture per scene, and the subscription's quota is for the ad prototypes.
        if (config('services.ai_image.paid_backend') === 'codex' && (string) config('services.ai_image.codex_token') !== '') {
            try {
                $bytes = $this->codex($prompt, $size, $refs);
                $this->lastBackend = 'codex';

                return $bytes;
            } catch (\Throwable $e) {
                // Quota, sign-in or a slow render: the metered API still delivers the ad.
                Log::warning('image: codex could not render, falling back to the API', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
        $this->lastBackend = 'openai';

        return $this->openai($prompt, $size);
    }

    /**
     * Several pictures from the Codex image agent at once, and never from the metered API: a
     * free prototype does not spend money. A picture the agent cannot make comes back null and
     * the caller falls back to what it had.
     *
     * @param  list<array{prompt:string,size:string,refs:list<string>}>  $jobs
     * @return list<?string> raw bytes per job
     */
    public function codexMany(array $jobs): array
    {
        if ($jobs === [] || (string) config('services.ai_image.codex_token') === '') {
            return array_fill(0, count($jobs), null);
        }
        $base = rtrim((string) config('services.ai_image.codex_url'), '/');
        $token = (string) config('services.ai_image.codex_token');
        $timeout = (int) config('services.ai_image.codex_timeout', 420);

        try {
            $responses = Http::pool(fn ($pool) => array_map(
                fn (array $job) => $pool->baseUrl($base)->withToken($token)->acceptJson()
                    ->timeout($timeout)->connectTimeout(10)
                    ->post('/v1/images', $this->payload($job['prompt'], $job['size'], $job['refs'])),
                $jobs,
            ));
        } catch (\Throwable $e) {
            Log::warning('image: codex pool failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return array_fill(0, count($jobs), null);
        }

        $out = [];
        foreach (array_values($responses) as $res) {
            if (! $res instanceof \Illuminate\Http\Client\Response || ! $res->successful()) {
                Log::info('image: codex could not render a prototype picture', ['status' => $res instanceof \Illuminate\Http\Client\Response ? $res->status() : null,
                    'error' => $res instanceof \Illuminate\Http\Client\Response ? mb_substr((string) $res->body(), 0, 200) : mb_substr((string) $res, 0, 200)]);
                $out[] = null;

                continue;
            }
            $bytes = base64_decode((string) $res->json('base64'), true);
            $out[] = $bytes === false || $bytes === '' ? null : $bytes;
        }

        return $out;
    }

    /** @param  list<string>  $refs */
    private function payload(string $prompt, string $size, array $refs): array
    {
        $payload = ['prompt' => $prompt, 'size' => $size, 'refs' => []];
        foreach (array_slice($refs, 0, 4) as $bytes) {
            $mime = (@getimagesizefromstring($bytes)['mime'] ?? null) ?: 'image/jpeg';
            $payload['refs'][] = ['mime' => $mime, 'data' => base64_encode($bytes)];
        }

        return $payload;
    }

    /** The image agent on the Codex (ChatGPT) subscription (infra/imagegen). */
    private function codex(string $prompt, string $size, array $refs): string
    {
        $payload = $this->payload($prompt, $size, $refs);

        $res = Http::baseUrl(rtrim((string) config('services.ai_image.codex_url'), '/'))
            ->withToken((string) config('services.ai_image.codex_token'))
            ->acceptJson()
            ->timeout((int) config('services.ai_image.codex_timeout', 420))
            ->connectTimeout(10)
            ->post('/v1/images', $payload);

        if (! $res->successful()) {
            throw new RuntimeException('Codex image agent answered '.$res->status().': '.mb_substr((string) $res->body(), 0, 300));
        }
        $bytes = base64_decode((string) $res->json('base64'), true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Codex image agent returned empty data.');
        }

        return $bytes;
    }

    private function openai(string $prompt, string $size): string
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Image service is not configured (AI_IMAGE_SERVICE_TOKEN).');
        }

        $payload = array_filter([
            'prompt' => $prompt,
            'size' => $size,
            'output_format' => 'png',
            'quality' => config('services.ai_image.quality'),
            'model' => config('services.ai_image.model'),
        ], fn ($v) => $v !== null && $v !== '');

        $res = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ai_image.timeout', 180))
            ->connectTimeout(10)
            ->post('/v1/images/generate', $payload);

        if (! $res->successful()) {
            throw new RuntimeException('Image generation failed ('.$res->status().'): '.mb_substr((string) $res->body(), 0, 300));
        }

        $b64 = (string) $res->json('base64');
        $bytes = $b64 === '' ? false : base64_decode($b64, true);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Image service returned empty data.');
        }

        return $bytes;
    }
}
