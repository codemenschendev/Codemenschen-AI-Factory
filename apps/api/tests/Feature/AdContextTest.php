<?php

namespace Tests\Feature;

use App\Domain\Ai\DesignStudy;
use App\Domain\Ai\ProductPage;
use App\Jobs\RenderProjectAd;
use App\Models\Order;
use App\Models\ProjectAd;
use App\Models\StoreAsset;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What the copywriter of a paid ad is told the ad is about. It used to be the project name, which
 * is the first sixty characters of the idea, and "expo".
 */
class AdContextTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $idea)
    {
        config(['services.stripe.secret' => null, 'services.worker.token' => 't']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);
        $quote = $this->postJson('/api/quotes', ['idea' => $idea, 'audience' => 'b2b', 'platform' => 'mobile', 'features' => []])->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'kunde@example.com', 'fagg_waiver' => true, 'terms' => true]);

        return app(OrderFulfillment::class)->markPaid(Order::firstOrFail(), 'pi', 100, []);
    }

    private function context(ProjectAd $ad, ?array $site = null): array
    {
        $m = new \ReflectionMethod(RenderProjectAd::class, 'context');

        return $m->invoke(new RenderProjectAd($ad->id), $ad, $site, app(ProductPage::class), app(DesignStudy::class));
    }

    public function test_without_a_page_the_ad_is_about_the_whole_idea_and_the_store_text(): void
    {
        $idea = 'Terminbuchung für einen Friseursalon: Kunden registrieren sich, sehen freie Zeitfenster, buchen und stornieren, bekommen Erinnerungen';
        $project = $this->project($idea);
        StoreAsset::create(['project_id' => $project->id, 'kind' => 'description', 'locale' => 'de', 'content' => 'Buche deinen Haarschnitt in zwei Minuten.']);
        $ad = ProjectAd::create(['project_id' => $project->id, 'kind' => 'image', 'source' => 'ai', 'name' => 'x', 'prompt' => 'Anzeige für neue Kunden', 'status' => 'queued', 'spec' => ['language' => 'de']]);

        $context = $this->context($ad);

        $this->assertSame($idea, $context['subject_description']);
        $this->assertSame('Buche deinen Haarschnitt in zwei Minuten.', $context['subject_store_description']);
        $this->assertSame('mobile app', $context['subject_platform']);
    }

    public function test_a_named_page_brings_its_product_brief_and_text(): void
    {
        Cache::flush();
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        $project = $this->project('Eine App');
        $this->app->instance(ProductPage::class, new ProductPage(fn () => ['93.184.216.34']));
        Http::fake([
            'https://wp-giftcard.com/' => Http::response('<html><head><title>WP Gift Card</title></head><body><h1>Sell vouchers on WordPress</h1><p>20,000 Downloads</p></body></html>', 200, ['Content-Type' => 'text/html']),
            '*/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'Product: a WordPress voucher plugin.']]]]),
        ]);
        $ad = ProjectAd::create(['project_id' => $project->id, 'kind' => 'image', 'source' => 'ai', 'name' => 'x', 'prompt' => 'Anzeige für wp-giftcard.com', 'status' => 'queued', 'spec' => []]);

        $context = $this->context($ad, ['url' => 'https://wp-giftcard.com', 'title' => 'WP Gift Card']);

        $this->assertSame('Product: a WordPress voucher plugin.', $context['subject_product_brief']);
        $this->assertStringContainsString('20,000 Downloads', $context['subject_website_text']);
        $this->assertSame('WP Gift Card', $context['subject_title']);
    }
}
