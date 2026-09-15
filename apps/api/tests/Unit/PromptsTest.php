<?php

namespace Tests\Unit;

use App\Domain\Ai\Prompts;
use RuntimeException;
use Tests\TestCase;

/**
 * The prompt files are edited by hand on GitHub, so what a careless edit can break is checked
 * here: a file that went missing, and a placeholder the code fills that no longer exists.
 */
class PromptsTest extends TestCase
{
    /** Every file the code asks for, with the placeholders it fills. */
    private const USED = [
        'ads/copywriter' => [], 'ads/video' => [], 'ads/still' => [], 'ads/reference' => [],
        'prototype/site' => [], 'prototype/app' => [], 'prototype/ads' => [],
        'prototype/laws' => [], 'prototype/photo-slots' => [], 'prototype/reference' => [],
        'study/plan-app' => ['brief', 'industries', 'types'],
        'study/plan-web' => ['brief', 'industries'],
        'study/app' => ['industry', 'peers', 'screens', 'stats', 'rules', 'images_are_data'],
        'study/ads' => ['industry', 'peers', 'stats', 'rules', 'images_are_data'],
        'study/site' => ['industry', 'peers', 'stats', 'rules', 'images_are_data'],
        'study/images-are-data' => [],
        'change/assistant' => ['language', 'status', 'mode', 'recent'],
    ];

    public function test_every_prompt_file_exists_and_keeps_its_placeholders(): void
    {
        foreach (self::USED as $name => $placeholders) {
            $text = Prompts::get($name);
            $this->assertNotSame('', $text, $name);
            foreach ($placeholders as $key) {
                $this->assertStringContainsString('{'.$key.'}', $text, "{$name} lost {{$key}}");
            }
        }
    }

    public function test_placeholders_are_filled_once_and_never_expanded_again(): void
    {
        $text = Prompts::get('study/plan-web', ['brief' => 'Bakery {industries}', 'industries' => 'food, retail']);

        $this->assertStringContainsString('Bakery {industries}', $text);
        $this->assertStringContainsString('one of [food, retail]', $text);
    }

    public function test_a_missing_file_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);

        Prompts::get('prototype/does-not-exist');
    }
}
