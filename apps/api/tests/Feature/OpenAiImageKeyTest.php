<?php

namespace Tests\Feature;

use App\Domain\Ai\ImageService;
use App\Domain\Ai\OpenAiImageKey;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Paid renders on the owner's OpenAI key from the admin panel; free prototypes stay on Codex. */
class OpenAiImageKeyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-svcacct-abcdefghijklmnopqrstuvwxyz1234';

    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($im);
        $this->png = (string) ob_get_clean();
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't',
            'services.ai_image.openai_url' => 'https://openai.test', 'services.ai_image.quality' => 'low',
            'services.ai_image.paid_backend' => 'openai',
            'services.ai_image.codex_url' => 'http://imagegen.test', 'services.ai_image.codex_token' => 'c']);
    }

    /** The first matching stub wins, so a test's own answers go in front of the defaults. */
    private function fake(array $first = []): void
    {
        Http::fake($first + [
            'openai.test/v1/models/*' => Http::response(['id' => 'gpt-image-1']),
            'openai.test/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode($this->png)]]]),
            'sidecar.test/*' => Http::response(['base64' => base64_encode($this->png)]),
            'imagegen.test/*' => Http::response(['base64' => base64_encode($this->png)]),
        ]);
    }

    private function admin(): string
    {
        return $this->consoleToken(Customer::create(['email' => 'ops@example.com', 'locale' => 'de', 'is_admin' => true]));
    }

    public function test_the_admin_sets_the_key_and_never_reads_it_back(): void
    {
        $this->fake();
        $token = $this->admin();

        $this->withToken($token)->postJson('/api/admin/image-key', ['api_key' => self::KEY])
            ->assertOk()->assertJsonPath('key.set', true)->assertJsonPath('key.hint', '…1234');

        $this->withToken($token)->getJson('/api/admin/image-key')->assertOk()
            ->assertJsonPath('key.set', true)->assertDontSee(self::KEY);
        $this->assertStringNotContainsString(self::KEY, json_encode(Setting::find('ai_image.openai_key')->value));
        $this->assertSame(self::KEY, OpenAiImageKey::get());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/models/') && $r->hasHeader('Authorization', 'Bearer '.self::KEY));
    }

    public function test_a_key_openai_refuses_is_not_stored(): void
    {
        $this->fake(['openai.test/v1/models/*' => Http::response(['error' => ['message' => 'Incorrect API key provided']], 401)]);

        $this->withToken($this->admin())->postJson('/api/admin/image-key', ['api_key' => self::KEY])
            ->assertStatus(422)->assertSee('Incorrect API key');
        $this->assertSame('', OpenAiImageKey::get());
    }

    public function test_a_customer_cannot_reach_it(): void
    {
        $this->fake();
        $customer = Customer::create(['email' => 'c@example.com', 'locale' => 'de']);
        $this->withToken($customer->createToken('p', ['portal'])->plainTextToken)
            ->postJson('/api/admin/image-key', ['api_key' => self::KEY])->assertForbidden();
    }

    public function test_a_paid_render_goes_to_openai_on_the_key(): void
    {
        $this->fake();
        OpenAiImageKey::put(self::KEY, 'ops@example.com');
        $images = app(ImageService::class);

        $this->assertSame($this->png, $images->generate('a bakery at dawn', '1024x1536'));
        $this->assertSame('openai-key', $images->lastBackend);
        Http::assertSent(fn ($r) => $r->url() === 'https://openai.test/v1/images/generations'
            && $r->hasHeader('Authorization', 'Bearer '.self::KEY) && $r['quality'] === 'low' && $r['size'] === '1024x1536');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sidecar.test'));
    }

    public function test_without_a_key_paid_renders_use_the_sidecar_as_before(): void
    {
        $this->fake();
        $images = app(ImageService::class);
        $images->generate('a bakery at dawn', '1024x1024');

        $this->assertSame('openai', $images->lastBackend);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.test'));
    }

    public function test_a_failing_key_falls_back_to_the_sidecar(): void
    {
        OpenAiImageKey::put(self::KEY, 'ops@example.com');
        $this->fake(['openai.test/*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        $images = app(ImageService::class);

        $this->assertSame($this->png, $images->generate('a bakery at dawn', '1024x1024'));
        $this->assertSame('openai', $images->lastBackend);
    }

    public function test_free_prototype_pictures_never_touch_the_key(): void
    {
        $this->fake();
        OpenAiImageKey::put(self::KEY, 'ops@example.com');

        app(ImageService::class)->codexMany([['prompt' => 'bread', 'size' => '1080x1080', 'refs' => []]]);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'imagegen.test'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.test'));
    }

    public function test_removing_the_key_sends_paid_renders_back_to_the_sidecar(): void
    {
        $this->fake();
        OpenAiImageKey::put(self::KEY, 'ops@example.com');

        $this->withToken($this->admin())->deleteJson('/api/admin/image-key')->assertOk()->assertJsonPath('key.set', false);
        $this->assertSame('', OpenAiImageKey::get());
    }
}
