<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;
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
    /** @return string Raw image bytes. */
    public function generate(string $prompt, string $size): string
    {
        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Chưa cấu hình dịch vụ sinh ảnh (AI_IMAGE_SERVICE_TOKEN).');
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
            throw new RuntimeException('Sinh ảnh thất bại ('.$res->status().'): '.mb_substr((string) $res->body(), 0, 300));
        }

        $b64 = (string) $res->json('base64');
        $bytes = $b64 === '' ? false : base64_decode($b64, true);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Dịch vụ sinh ảnh trả về dữ liệu rỗng.');
        }

        return $bytes;
    }
}
