<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VisionCheckTest extends TestCase
{
    private string $alerts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alerts = sys_get_temp_dir().'/vision-check-'.uniqid();
        mkdir($this->alerts);
        config(['services.worker.token' => 't', 'services.buzz.alert_dir' => $this->alerts, 'services.openclaw.hook_url' => null]);
    }

    private function alerts(): array
    {
        return array_values(array_filter(scandir($this->alerts), fn ($f) => str_ends_with($f, '.json')));
    }

    public function test_a_model_that_reads_the_picture_passes_quietly(): void
    {
        Http::fake(['*/vision-check' => Http::response(['text' => 'Zimtstern 58'])]);

        $this->artisan('factory:vision-check')->assertSuccessful();

        Http::assertSent(fn ($r) => str_starts_with($r['image'], 'data:image/png;base64,'));
        $this->assertSame([], $this->alerts());
    }

    public function test_a_model_that_sees_no_picture_alerts(): void
    {
        Http::fake(['*/vision-check' => Http::response(['text' => 'NO IMAGE'])]);

        $this->artisan('factory:vision-check')->assertFailed();

        $this->assertCount(1, $this->alerts());
        $this->assertStringContainsString('imageArg', file_get_contents($this->alerts.'/'.$this->alerts()[0]));
    }

    public function test_a_worker_that_is_down_alerts(): void
    {
        Http::fake(['*/vision-check' => Http::response(['error' => 'down'], 503)]);

        $this->artisan('factory:vision-check')->assertFailed();

        $this->assertCount(1, $this->alerts());
    }
}
