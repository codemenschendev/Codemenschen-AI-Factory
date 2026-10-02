<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileTest extends TestCase
{
    public function test_without_a_secret_the_check_is_off(): void
    {
        config(['services.turnstile.secret' => null]);
        Http::fake();
        // Fails later on validation, not on the bot check.
        $this->postJson('/api/quotes/refine', [])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_missing_token_is_refused(): void
    {
        config(['services.turnstile.secret' => 's']);
        $this->postJson('/api/prototypes', ['prompt' => 'x'])->assertStatus(403)->assertJsonPath('error', 'turnstile');
    }

    public function test_a_token_cloudflare_rejects_is_refused_and_a_good_one_passes(): void
    {
        config(['services.turnstile.secret' => 's']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
            ->push(['success' => true])]);

        $this->withHeader('X-Turnstile', 'bad')->postJson('/api/quotes/refine', [])->assertStatus(403);
        $this->withHeader('X-Turnstile', 'good')->postJson('/api/quotes/refine', [])->assertStatus(422);
    }

    public function test_cloudflare_down_lets_the_request_through(): void
    {
        config(['services.turnstile.secret' => 's']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response('', 503)]);
        $this->withHeader('X-Turnstile', 't')->postJson('/api/quotes/refine', [])->assertStatus(422);
    }
}
