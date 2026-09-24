<?php

namespace App\Domain\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * The OpenAI API key paid renders are billed to, entered by the owner in the admin panel
 * (2026-09-24). With a key set, a paying customer's ad pictures go straight to the OpenAI Images
 * API on that key; without one they keep going through the host's image sidecar. Free prototypes
 * never touch it: they stay on the Codex subscription (ImageService::codexOn/codexMany).
 *
 * The key is written once and never read back to a browser. The database holds it encrypted with
 * the app key; the panel sees only whether one is set and its last four characters.
 */
class OpenAiImageKey
{
    private const KEY = 'ai_image.openai_key';

    /** The key in use, or '' when none is set or it can no longer be decrypted. */
    public static function get(): string
    {
        $row = Setting::read(self::KEY);
        if (! is_array($row) || ($row['enc'] ?? '') === '') {
            return '';
        }

        try {
            return Crypt::decryptString((string) $row['enc']);
        } catch (\Throwable $e) {
            // A rotated APP_KEY leaves an unreadable blob: act as if no key were set.
            Log::warning('image: stored OpenAI key cannot be decrypted, paid renders use the sidecar');

            return '';
        }
    }

    /** @return array{set:bool,hint:?string,by:?string,at:?string} what the panel may show */
    public static function status(): array
    {
        $row = Setting::read(self::KEY);
        $set = self::get() !== '';

        return [
            'set' => $set,
            'hint' => $set ? ($row['hint'] ?? null) : null,
            'by' => $set ? ($row['by'] ?? null) : null,
            'at' => $set ? ($row['at'] ?? null) : null,
        ];
    }

    public static function put(string $key, string $by): void
    {
        Setting::write(self::KEY, [
            'enc' => Crypt::encryptString($key),
            'hint' => '…'.substr($key, -4),
            'by' => $by,
            'at' => now()->toIso8601String(),
        ], $by);
    }

    public static function clear(string $by): void
    {
        Setting::write(self::KEY, null, $by);
    }
}
