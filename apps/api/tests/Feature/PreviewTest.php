<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Project;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PreviewTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => null, 'services.worker.token' => 't', 'app.url' => 'https://api.test']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
        $quote = $this->postJson('/api/quotes', ['idea' => 'x', 'audience' => 'b2b', 'platform' => 'web', 'features' => ['dash']])->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'prev@example.com', 'fagg_waiver' => true]);
        $this->project = app(OrderFulfillment::class)->markPaid(Order::firstOrFail(), 'pi', 100, []);

        $this->dir = sys_get_temp_dir().'/factory-artifacts-'.uniqid();
        config(['services.worker.artifacts_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            exec('rm -rf '.escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    private function exportWeb(): void
    {
        $web = "{$this->dir}/{$this->project->id}/web";
        mkdir("$web/_expo/static", 0777, true);
        file_put_contents("$web/index.html", '<!doctype html><div id="root">preview</div>');
        file_put_contents("$web/_expo/static/app.js", 'console.log(1)');
        $this->project->builds()->create(['platform' => 'web', 'version' => '0.1.0', 'artifact_path' => "{$this->project->id}/web", 'status' => 'preview']);
    }

    public function test_preview_url_appears_once_a_web_build_exists(): void
    {
        $token = $this->project->customer->createToken('portal')->plainTextToken;
        $this->withHeader('Authorization', "Bearer $token")->getJson("/api/me/projects/{$this->project->id}")
            ->assertOk()->assertJsonPath('preview_url', null);
        $this->exportWeb();
        $this->withHeader('Authorization', "Bearer $token")->getJson("/api/me/projects/{$this->project->id}")
            ->assertOk()->assertJsonPath('preview_url', "https://api.test/api/preview/{$this->project->id}/");
    }

    public function test_preview_serves_shell_assets_and_spa_fallback(): void
    {
        $id = $this->project->id;
        $this->get("/api/preview/$id/")->assertNotFound(); // nothing exported yet
        $this->exportWeb();

        $served = fn (string $url) => basename($this->get($url)->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertSame('index.html', $served("/api/preview/$id/"));
        $this->get("/api/preview/$id/")->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->get("/api/preview/$id/")
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Embedder-Policy', 'credentialless');
        $this->assertSame('app.js', $served("/api/preview/$id/_expo/static/app.js"));
        $this->get("/api/preview/$id/_expo/static/app.js")->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertSame('index.html', $served("/api/preview/$id/settings/profile")); // client-side route
        $this->get("/api/preview/$id/missing.png")->assertNotFound();
        $this->get("/api/preview/$id/../../etc/passwd")->assertNotFound();
        $this->get('/api/preview/00000000-0000-0000-0000-000000000000/')->assertNotFound();
    }
}
