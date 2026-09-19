<?php

namespace Tests\Feature;

use App\Domain\Ai\PrototypeWriter;
use App\Jobs\RevisePrototype;
use App\Models\Customer;
use App\Models\Prototype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The one free change a signed-in visitor may ask for on a prototype. */
class PrototypeReviseTest extends TestCase
{
    use RefreshDatabase;

    private function proto(array $attrs = []): Prototype
    {
        return Prototype::create($attrs + ['status' => 'ready', 'kind' => 'site', 'prompt' => 'Eine Bäckerei in Graz',
            'html' => '<html><head><title>Bäckerei</title></head><body><h1>Frisches Brot</h1><img src="data:image/png;base64,AAAA"></body></html>',
            'ip' => '203.0.113.7', 'expires_at' => now()->addDays(3)]);
    }

    private function customer(string $email = 'kunde@example.com', bool $admin = false): Customer
    {
        return Customer::create(['email' => $email, 'locale' => 'de', 'is_admin' => $admin]);
    }

    public function test_a_visitor_must_sign_in_to_change_a_prototype(): void
    {
        $this->postJson('/api/prototypes/'.$this->proto()->id.'/revise', ['change' => 'Die Überschrift in Blau'])->assertUnauthorized();
    }

    public function test_one_change_then_it_is_used_and_the_prototype_is_theirs(): void
    {
        Queue::fake();
        $proto = $this->proto();
        $me = $this->customer();

        $this->actingAs($me, 'sanctum')->postJson("/api/prototypes/{$proto->id}/revise", ['change' => 'Die Überschrift in Blau'])->assertStatus(202);
        Queue::assertPushed(RevisePrototype::class);
        $this->assertSame($me->id, $proto->fresh()->customer_id);
        $this->getJson("/api/prototypes/{$proto->id}")->assertJson(['status' => 'building', 'revisions_left' => 0]);

        $proto->fresh()->update(['status' => 'ready']);
        $this->actingAs($me, 'sanctum')->postJson("/api/prototypes/{$proto->id}/revise", ['change' => 'Noch etwas anderes'])
            ->assertStatus(403)->assertJson(['code' => 'used']);
    }

    public function test_someone_else_cannot_use_a_claimed_prototype_and_an_admin_is_not_counted(): void
    {
        Queue::fake();
        $proto = $this->proto(['customer_id' => $this->customer()->id]);

        $this->actingAs($this->customer('other@example.com'), 'sanctum')->postJson("/api/prototypes/{$proto->id}/revise", ['change' => 'Andere Farben bitte'])
            ->assertStatus(403)->assertJson(['code' => 'not_yours']);

        $proto->update(['revisions' => 5]);
        $this->actingAs($this->customer('chef@example.com', true), 'sanctum')->postJson("/api/prototypes/{$proto->id}/revise", ['change' => 'Andere Farben bitte'])
            ->assertStatus(202);
    }

    public function test_the_change_is_patched_with_the_pictures_put_back(): void
    {
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        Http::fake(['*/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => "<<<FIND\n<h1>Frisches Brot</h1><img src=\"img:1\">\n===\n<h1 style=\"color:blue\">Frisches Brot</h1><img src=\"img:1\">\n>>>"]]]])]);
        $proto = $this->proto(['status' => 'building', 'revisions' => 1]);

        (new RevisePrototype($proto->id, 'Die Überschrift in Blau'))->handle(app(PrototypeWriter::class), app(\App\Domain\Ai\PrototypePhoto::class));

        $proto->refresh();
        $this->assertSame('ready', $proto->status);
        $this->assertStringContainsString('<h1 style="color:blue">Frisches Brot</h1><img src="data:image/png;base64,AAAA">', $proto->html);
        $this->assertSame('Die Überschrift in Blau', $proto->qa['revision']['request']);
        Http::assertSent(fn ($r) => ! str_contains(json_encode($r->data()), 'base64,AAAA'));
    }

    public function test_a_failed_change_keeps_the_page_and_gives_the_change_back(): void
    {
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        Http::fake(['*/v1/chat/completions' => Http::response('down', 500)]);
        $proto = $this->proto(['status' => 'building', 'revisions' => 1]);
        $before = $proto->html;

        (new RevisePrototype($proto->id, 'Die Überschrift in Blau'))->handle(app(PrototypeWriter::class), app(\App\Domain\Ai\PrototypePhoto::class));

        $proto->refresh();
        $this->assertSame(['ready', 0, $before], [$proto->status, $proto->revisions, $proto->html]);
        $this->getJson("/api/prototypes/{$proto->id}")->assertJson(['revisions_left' => 1, 'revision_failed' => true]);
    }
}
