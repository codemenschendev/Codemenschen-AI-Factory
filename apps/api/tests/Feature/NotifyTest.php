<?php

namespace Tests\Feature;

use App\Models\Prototype;
use App\Services\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The operators hear about what broke without opening the admin tab.
 */
class NotifyTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/notify-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_failed_prototype_leaves_one_alert_for_buzz_and_mails_the_admin(): void
    {
        config(['services.buzz.alert_dir' => $this->dir, 'services.admin_email' => 'ops@example.com',
            'services.openclaw.hook_url' => null]);
        Mail::fake();
        $proto = Prototype::create(['status' => 'failed', 'kind' => 'site', 'prompt' => 'Bäckerei in Linz',
            'error' => 'The agent answered twice without HTML.', 'expires_at' => now()->addDays(7)]);

        app(Notify::class)->prototypeFailed($proto);

        $files = glob($this->dir.'/*.json');
        $this->assertCount(1, $files);
        $this->assertSame([], glob($this->dir.'/.*.json'), 'no half-written file left behind');
        $alert = json_decode(file_get_contents($files[0]), true);
        $this->assertStringStartsWith('Appwerk: Prototype '.substr($proto->id, 0, 8), $alert['message']);
        $this->assertStringContainsString('answered twice without HTML', $alert['message']);
    }

    public function test_without_a_drop_folder_nothing_is_written_and_nothing_is_sent(): void
    {
        config(['services.buzz.alert_dir' => null, 'services.openclaw.hook_url' => null, 'services.admin_email' => null]);
        Http::fake();
        $proto = Prototype::create(['status' => 'failed', 'kind' => 'ads', 'prompt' => 'x', 'error' => 'y', 'expires_at' => now()->addDay()]);

        app(Notify::class)->prototypeFailed($proto);

        Http::assertNothingSent();
        $this->assertSame([], glob($this->dir.'/*'));
    }

    public function test_a_folder_that_is_not_mounted_is_skipped_not_thrown(): void
    {
        config(['services.buzz.alert_dir' => $this->dir.'/missing', 'services.openclaw.hook_url' => null, 'services.admin_email' => null]);
        $proto = Prototype::create(['status' => 'failed', 'kind' => 'app', 'prompt' => 'x', 'error' => 'y', 'expires_at' => now()->addDay()]);

        app(Notify::class)->prototypeFailed($proto);

        $this->assertDirectoryDoesNotExist($this->dir.'/missing');
    }
}
