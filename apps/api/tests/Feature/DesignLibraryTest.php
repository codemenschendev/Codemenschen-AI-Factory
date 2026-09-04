<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Browsing the reference library.
 *
 * The library is a directory on the host, not a table, so these tests build a small one in a temp
 * directory and point the config at it. What matters: only an admin gets the listing, the filters
 * actually narrow, and an image comes out only through a signed URL.
 */
class DesignLibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/design-library-'.uniqid());
        File::makeDirectory($this->dir.'/images/app-screen/detail', 0755, true);
        File::makeDirectory($this->dir.'/images/app-screen/layout', 0755, true);

        // A real 1x1 webp would do; the controller only ever streams the bytes back.
        File::put($this->dir.'/images/app-screen/detail/aaa.webp', 'not-really-a-webp');
        File::put($this->dir.'/images/app-screen/detail/bbb.webp', 'not-really-a-webp');
        File::put($this->dir.'/images/app-screen/layout/ccc.webp', 'not-really-a-webp');

        File::put($this->dir.'/catalog.json', json_encode(['images' => [
            $this->record('aaa', 'detail', ['screen_type' => 'calendar_booking', 'industry' => 'beauty_salon',
                'layout_patterns' => ['sticky_cta', 'list_rows'], 'primary_action' => 'Termin buchen',
                'density' => 'sparse', 'palette' => ['scheme' => 'light', 'accent' => 'green'],
                'notes' => 'booking with a sticky action']),
            $this->record('bbb', 'detail', ['screen_type' => 'home_dashboard', 'industry' => 'finance_banking',
                'layout_patterns' => ['bottom_tab_bar', 'stat_tiles'], 'primary_action' => null,
                'density' => 'medium', 'palette' => ['scheme' => 'dark', 'accent' => 'blue'],
                'notes' => 'numbers in tiles']),
            // Unlabelled on purpose: the panel must be able to find what still needs work.
            $this->record('ccc', 'layout', ['screen_type' => null, 'industry' => null, 'layout_patterns' => [],
                'primary_action' => null, 'density' => null, 'palette' => ['scheme' => null, 'accent' => null],
                'notes' => null]),
        ]]));

        config(['services.media.design_library_path' => $this->dir]);
        $this->adminToken = Customer::create(['email' => 'ops@example.com', 'locale' => 'de', 'is_admin' => true])
            ->createToken('portal')->plainTextToken;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<string,mixed> $labels */
    private function record(string $id, string $grade, array $labels): array
    {
        return [
            'id' => $id,
            'medium' => 'app',
            'file' => "images/app-screen/$grade/$id.webp",
            'byte_size' => 17,
            'visual' => ['category' => 'app-screen', 'grade' => $grade, 'width' => 720, 'height' => 1560,
                'orientation' => 'portrait'],
            'sources' => [['website' => ['domain' => 'mobbin.com', 'label' => 'popular · mobbin.com']]],
            'ai_index' => ['search_text' => "app-screen | mobbin.com | $id"],
            'labels' => $labels,
        ];
    }

    private function asAdmin(): array
    {
        return ['Authorization' => 'Bearer '.$this->adminToken];
    }

    public function test_a_customer_cannot_browse_the_reference_library(): void
    {
        $token = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de'])
            ->createToken('portal')->plainTextToken;

        $this->getJson('/api/admin/design-library', ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }

    /**
     * Its own test rather than a second call above: the guard caches the user it resolved, so two
     * differently authenticated requests in one test both answer as the first one.
     */
    public function test_a_stranger_cannot_browse_the_reference_library(): void
    {
        $this->getJson('/api/admin/design-library')->assertUnauthorized();
    }

    public function test_the_listing_counts_what_is_there_and_what_is_labelled(): void
    {
        $r = $this->getJson('/api/admin/design-library', $this->asAdmin())->assertOk();

        $r->assertJsonPath('available', true)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('labelled', 2)
            ->assertJsonPath('matched', 3)
            ->assertJsonPath('facets.screen_type.calendar_booking', 1)
            ->assertJsonPath('facets.grade.detail', 2)
            ->assertJsonPath('facets.pattern.sticky_cta', 1);
    }

    public function test_the_filters_narrow_the_page(): void
    {
        $this->getJson('/api/admin/design-library?screen_type=calendar_booking', $this->asAdmin())
            ->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('items.0.id', 'aaa');

        $this->getJson('/api/admin/design-library?pattern=stat_tiles', $this->asAdmin())
            ->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('items.0.id', 'bbb');

        $this->getJson('/api/admin/design-library?scheme=dark', $this->asAdmin())
            ->assertOk()->assertJsonPath('matched', 1);

        $this->getJson('/api/admin/design-library?labelled=no', $this->asAdmin())
            ->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('items.0.id', 'ccc');

        $this->getJson('/api/admin/design-library?q=sticky', $this->asAdmin())
            ->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('items.0.id', 'aaa');

        $this->getJson('/api/admin/design-library?screen_type=calendar_booking&scheme=dark', $this->asAdmin())
            ->assertOk()->assertJsonPath('matched', 0);
    }

    public function test_the_listing_hands_out_no_source_urls(): void
    {
        // The panel shows where a screen came from, not the scraped URL behind it.
        $body = $this->getJson('/api/admin/design-library', $this->asAdmin())->assertOk()->content();

        $this->assertStringNotContainsString('page_url', $body);
        $this->assertStringNotContainsString('sha256', $body);
    }

    public function test_an_image_needs_a_signature(): void
    {
        $url = $this->getJson('/api/admin/design-library', $this->asAdmin())->json('items.0.url');

        $this->get('/api/admin/design-library/aaa/image')->assertForbidden();
        $this->get($url)->assertOk()->assertHeader('content-type', 'image/webp');
    }

    public function test_an_id_outside_the_catalog_is_a_404_not_a_path(): void
    {
        // The signature proves the caller asked us, not that the file exists or is even ours.
        $url = URL::temporarySignedRoute(
            'admin.design-library.image', now()->addHour(), ['id' => '../../../etc/passwd'],
        );

        $this->get($url)->assertNotFound();
    }

    public function test_a_missing_library_says_so_instead_of_failing(): void
    {
        config(['services.media.design_library_path' => $this->dir.'/nope']);

        $this->getJson('/api/admin/design-library', $this->asAdmin())
            ->assertOk()->assertJsonPath('available', false)->assertJsonPath('total', 0);
    }
}
