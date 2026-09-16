<?php

namespace Tests\Feature;

use App\Domain\Ai\PrototypeWriter;
use App\Domain\Design\Layouts;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Layout packs: off until an operator switches them on, and never on for a kind that was not
 * asked for. The switch is the product here, so the tests are about the switch, not the skeleton.
 */
class LayoutsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/layouts-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        config(['services.layouts.path' => $this->dir, 'services.buzz.alert_dir' => null]);

        $admin = Customer::create(['email' => 'admin@example.com', 'locale' => 'de', 'is_admin' => true]);
        $this->token = $admin->createToken('portal')->plainTextToken;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function pack(string $slug, string $kind = 'site', array $industries = [], string $source = 'lovable'): void
    {
        file_put_contents($this->dir.'/'.$slug.'.html', "<!doctype html><html><head><style>body{margin:0}</style></head><body><header>{$slug}</header></body></html>");
        $packs = json_decode((string) @file_get_contents($this->dir.'/packs.json'), true)['packs'] ?? [];
        $packs[] = ['slug' => $slug, 'kind' => $kind, 'industries' => $industries, 'source' => $source, 'note' => ''];
        file_put_contents($this->dir.'/packs.json', json_encode(['packs' => $packs]));
    }

    private function layouts(): Layouts
    {
        Cache::flush();

        return new Layouts($this->dir);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    public function test_nothing_is_picked_until_an_operator_switches_it_on(): void
    {
        $this->pack('bakery-warm', 'site', ['Bäckerei']);

        $this->assertNull($this->layouts()->pick('site', 'Eine Bäckerei in Graz'));

        Setting::write('layouts.enabled', true);
        $this->assertNotNull($this->layouts()->pick('site', 'Eine Bäckerei in Graz'));
    }

    public function test_a_kind_that_was_not_asked_for_writes_from_nothing(): void
    {
        $this->pack('bakery-warm', 'site');
        $this->pack('booking-app', 'app');
        Setting::write('layouts.enabled', true);
        Setting::write('layouts.kinds', ['site']);

        $this->assertNotNull($this->layouts()->pick('site', 'Eine Bäckerei'));
        $this->assertNull($this->layouts()->pick('app', 'Eine Bäckerei'));
    }

    public function test_the_trade_named_by_a_pack_wins_over_the_others(): void
    {
        $this->pack('generic', 'site');
        $this->pack('dental-clean', 'site', ['Zahnarzt', 'dentist']);
        Setting::write('layouts.enabled', true);

        for ($i = 0; $i < 12; $i++) {
            $this->assertSame('dental-clean', $this->layouts()->pick('site', 'Ein Zahnarzt in Wien')['slug']);
        }
    }

    public function test_a_share_of_zero_is_a_control_group_and_a_pack_can_be_held_back(): void
    {
        $this->pack('bakery-warm', 'site');
        Setting::write('layouts.enabled', true);

        Setting::write('layouts.share', 0);
        $this->assertNull($this->layouts()->pick('site', 'Eine Bäckerei'));

        Setting::write('layouts.share', 100);
        Setting::write('layouts.off', ['bakery-warm']);
        $this->assertNull($this->layouts()->pick('site', 'Eine Bäckerei'));
    }

    public function test_a_manifest_line_without_a_file_behind_it_is_ignored(): void
    {
        file_put_contents($this->dir.'/packs.json', json_encode(['packs' => [
            ['slug' => 'ghost', 'kind' => 'site', 'industries' => [], 'source' => 'lovable'],
        ]]));
        Setting::write('layouts.enabled', true);

        $this->assertSame([], $this->layouts()->packs());
        $this->assertNull($this->layouts()->pick('site', 'Eine Bäckerei'));
    }

    public function test_the_operator_switches_packs_from_the_admin_panel(): void
    {
        $this->pack('bakery-warm', 'site', ['Bäckerei']);

        $this->getJson('/api/admin/overview', $this->auth())
            ->assertOk()
            ->assertJsonPath('layouts.enabled', false)
            ->assertJsonPath('layouts.by_kind.site', 1)
            ->assertJsonPath('layouts.packs.0.source', 'lovable');

        $this->postJson('/api/admin/layouts', [
            'enabled' => true, 'kinds' => ['site', 'ads'], 'share' => 70,
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('share', 70)
            ->assertJsonPath('kinds', ['site', 'ads']);

        Cache::flush();
        $this->assertTrue(app(Layouts::class)->enabled());
    }

    public function test_a_customer_cannot_touch_the_switch(): void
    {
        $token = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de'])
            ->createToken('portal')->plainTextToken;

        $this->postJson('/api/admin/layouts', ['enabled' => true], ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();

        $this->assertFalse($this->layouts()->enabled());
    }

    public function test_a_switched_on_pack_travels_with_the_prompt_and_is_recorded(): void
    {
        $this->pack('bakery-warm', 'site');
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        Http::fake(['*/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '<!doctype html><html><head><title>X</title></head><body><h1>X</h1></body></html>']]],
        ])]);

        // Off: nothing travels, and the page records that it followed no skeleton. Without that
        // line the comparison the whole switch exists for cannot be made after the fact.
        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'site', null, null, null, null, null, $this->layouts());
        $this->assertNull($out['qa']['layout']);

        Setting::write('layouts.enabled', true);
        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'site', null, null, null, null, null, $this->layouts());
        $this->assertSame(['slug' => 'bakery-warm', 'source' => 'lovable'], $out['qa']['layout']);

        $sent = '';
        Http::assertSent(function ($request) use (&$sent) {
            $sent = (string) $request['messages'][0]['content'];

            return true;
        });
        $this->assertStringContainsString('bakery-warm', $sent);
        $this->assertStringContainsString('Follow its structure', $sent);
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->postJson('/api/admin/layouts', ['kinds' => ['poster']], $this->auth())
            ->assertStatus(422);
    }

    public function test_the_shipped_packs_are_on_disk_and_obey_the_page_laws(): void
    {
        $shipped = new Layouts(resource_path('layouts'));
        $packs = $shipped->packs();
        $this->assertSame(['bakery-warm', 'physio-calm'], array_column($packs, 'slug'));

        foreach ($packs as $p) {
            $html = (string) file_get_contents(resource_path('layouts/'.$p['slug'].'.html'));
            $this->assertSame('site', $p['kind']);
            $this->assertSame(1, substr_count($html, '<style>'), $p['slug']);
            $this->assertStringNotContainsString('<script', $html, $p['slug']);
            $this->assertDoesNotMatchRegularExpression('#(src|href)="(https?:)?//#', $html, $p['slug']);
            $this->assertStringNotContainsString('—', $html, $p['slug']);
            $this->assertStringContainsString('data-q="', $html, $p['slug']);
            $this->assertLessThan(20000, strlen($html), $p['slug']);
        }
    }

    public function test_a_bakery_sentence_gets_the_bakery_pack_and_a_practice_gets_the_practice(): void
    {
        Setting::write('layouts.enabled', true);
        $shipped = new Layouts(resource_path('layouts'));

        for ($i = 0; $i < 8; $i++) {
            $this->assertSame('bakery-warm', $shipped->pick('site', 'Eine Bäckerei in Gössendorf mit Sonntagsbrot')['slug']);
            $this->assertSame('physio-calm', $shipped->pick('site', 'Meine Physiotherapie Praxis in Linz')['slug']);
        }
    }
}
