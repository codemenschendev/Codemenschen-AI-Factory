<?php

namespace Tests\Feature;

use App\Domain\Ai\Campaign;
use App\Jobs\BuildPrototype;
use App\Models\Customer;
use App\Models\Prototype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The campaign prototype: one message, then the ad, the landing page and the e-mails. */
class CampaignPrototypeTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(array $attrs = []): Prototype
    {
        return Prototype::create($attrs + ['status' => 'queued', 'kind' => 'campaign', 'prompt' => 'Eine Bäckerei in Graz mit Brot-Abo',
            'ip' => '203.0.113.7', 'expires_at' => now()->addDays(7)]);
    }

    public function test_the_message_is_written_once_and_the_three_parts_start_with_it(): void
    {
        Queue::fake();
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        Http::fake(['*/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '{"audience":"Families in Graz",'
            .'"promise":"Fresh bread at your door every morning","reasons":["Baked at night","Organic flour"],'
            .'"action":"join the waitlist","offer":"","tone":"warm, local, honest"}']]]])]);
        $proto = $this->campaign();

        (new BuildPrototype($proto->id, 'campaign'))->handle(...array_map('app', [\App\Domain\Ai\PrototypeWriter::class,
            \App\Domain\Design\DesignRefs::class, \App\Domain\Qa\PageAudit::class, \App\Domain\Design\DesignLibrary::class,
            \App\Domain\Ai\PrototypePhoto::class, \App\Domain\Design\Layouts::class]));

        $proto->refresh();
        $this->assertSame('building', $proto->status);
        $this->assertSame('Fresh bread at your door every morning', $proto->title);
        $parts = Prototype::where('parent_id', $proto->id)->get();
        $this->assertEqualsCanonicalizing(['ads', 'site', 'email'], $parts->pluck('kind')->all());
        foreach ($parts as $part) {
            $this->assertStringContainsString('The promise: Fresh bread at your door every morning', $part->prompt);
            $this->assertStringStartsWith('Eine Bäckerei in Graz mit Brot-Abo', $part->prompt);
        }
        $this->assertStringContainsString('sign-up form', $parts->firstWhere('kind', 'site')->prompt);
        Queue::assertPushed(BuildPrototype::class, 3);
    }

    public function test_the_campaign_is_ready_when_the_last_part_is_done(): void
    {
        $proto = $this->campaign(['status' => 'building']);
        $make = fn (string $kind, string $status) => Prototype::create(['parent_id' => $proto->id, 'kind' => $kind, 'status' => $status,
            'prompt' => 'x', 'ip' => '203.0.113.7', 'title' => ucfirst($kind), 'expires_at' => now()->addDays(7)]);
        $ad = $make('ads', 'ready');
        $site = $make('site', 'building');
        $make('email', 'failed');

        Campaign::settle($proto->id);
        $this->assertSame('building', $proto->fresh()->status);

        $site->update(['status' => 'ready']);
        Campaign::settle($proto->id);
        $this->assertSame('ready', $proto->fresh()->status);
        $this->assertSame('Site', $proto->fresh()->title);

        $parts = $this->getJson("/api/prototypes/{$proto->id}")->assertOk()->json('parts');
        $this->assertSame(['ads', 'site', 'email'], array_column($parts, 'kind'));
        $this->assertSame($ad->id, $parts[0]['id']);
    }

    public function test_a_campaign_where_every_part_failed_is_failed(): void
    {
        $proto = $this->campaign(['status' => 'building']);
        foreach (Campaign::PARTS as $kind) {
            Prototype::create(['parent_id' => $proto->id, 'kind' => $kind, 'status' => 'failed', 'prompt' => 'x',
                'ip' => '203.0.113.7', 'expires_at' => now()->addDays(7)]);
        }

        Campaign::settle($proto->id);

        $this->assertSame('failed', $proto->fresh()->status);
    }

    public function test_the_parts_share_one_free_change(): void
    {
        Queue::fake();
        $me = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $proto = $this->campaign(['status' => 'ready', 'customer_id' => $me->id]);
        $parts = [];
        foreach (Campaign::PARTS as $kind) {
            $parts[$kind] = Prototype::create(['parent_id' => $proto->id, 'kind' => $kind, 'status' => 'ready', 'prompt' => 'x',
                'html' => '<html><body><h1>Brot</h1></body></html>', 'customer_id' => $me->id,
                'ip' => '203.0.113.7', 'expires_at' => now()->addDays(7)]);
        }

        $this->actingAs($me, 'sanctum')->postJson("/api/prototypes/{$parts['site']->id}/revise", ['change' => 'Den Button grün machen'])->assertStatus(202);
        $this->actingAs($me, 'sanctum')->postJson("/api/prototypes/{$parts['ads']->id}/revise", ['change' => 'Den Button grün machen'])
            ->assertStatus(403)->assertJson(['code' => 'used']);
        $this->getJson("/api/prototypes/{$parts['email']->id}")->assertJson(['revisions_left' => 0]);
        $this->actingAs($me, 'sanctum')->postJson("/api/prototypes/{$proto->id}/revise", ['change' => 'Den Button grün machen'])->assertStatus(422);
    }

    public function test_the_parts_do_not_count_against_the_daily_cap(): void
    {
        $proto = $this->campaign(['status' => 'ready']);
        foreach (range(1, 6) as $n) {
            Prototype::create(['parent_id' => $proto->id, 'kind' => 'site', 'status' => 'ready', 'prompt' => 'x',
                'ip' => '203.0.113.7', 'expires_at' => now()->addDays(7)]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/prototypes', ['prompt' => 'Eine Kampagne für mein Café', 'kind' => 'campaign', 'email' => 'a@example.com'])
            ->assertStatus(202)->assertJson(['status' => 'waiting']);
    }
}
