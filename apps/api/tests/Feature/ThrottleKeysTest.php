<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each throttled route counts on its own. They all shared one count per IP, so page views on the
 * analytics beacon locked a visitor out of signing in for most of an hour (2026-09-18).
 */
class ThrottleKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_views_do_not_use_up_the_sign_in(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/t', ['event' => 'page_view', 'path' => '/de']);
        }

        $this->postJson('/api/auth/magic-link', ['email' => 'nobody@example.com', 'locale' => 'de'])->assertOk();
    }
}
