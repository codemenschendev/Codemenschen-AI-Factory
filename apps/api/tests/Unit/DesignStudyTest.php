<?php

namespace Tests\Unit;

use App\Domain\Ai\DesignStudy;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The two short calls before a build: name the trade and the screens, then look and write. */
class DesignStudyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
    }

    private function answer(string $text): void
    {
        Http::fake(['*/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => $text]]]])]);
    }

    public function test_the_plan_is_read_from_the_model_s_json_even_in_a_fence(): void
    {
        $this->answer("```json\n{\"industry\":\"transport_mobility\",\"screens\":[\"map\",\"list_feed\",\"form_input\",\"success_confirmation\"],\"apps\":[\"Grab\",\"Be\",\"Xanh SM\"],\"country\":\"VN\"}\n```");

        $plan = app(DesignStudy::class)->plan('App gọi xe ở Hà Nội');

        $this->assertSame('transport_mobility', $plan['industry']);
        $this->assertSame(['map', 'list_feed', 'form_input', 'success_confirmation'], $plan['screens']);
        $this->assertSame(['Grab', 'Be', 'Xanh SM'], $plan['apps']);
        $this->assertSame('vn', $plan['country']);
    }

    public function test_a_plan_outside_the_vocabulary_falls_back_rather_than_failing(): void
    {
        $this->answer('{"industry":"rockets","screens":["launchpad"],"apps":"SpaceX, NASA","country":"Mars"}');

        $plan = app(DesignStudy::class)->plan('Eine App für ein Friseurstudio in Wien');

        $this->assertSame('beauty_salon', $plan['industry'], 'the German word list still knows a salon');
        $this->assertSame(['home_dashboard', 'list_feed', 'form_input', 'success_confirmation'], $plan['screens']);
        $this->assertSame(['SpaceX', 'NASA'], $plan['apps']);
        $this->assertSame('at', $plan['country']);
    }

    public function test_a_site_plan_names_the_businesses_a_customer_already_knows(): void
    {
        // A website has no store to look in: the plan names the trade's best-known businesses
        // by domain, and the study photographs their live homepages.
        $this->answer('{"industry":"restaurant","sites":["figlmueller.at","https://plachutta.at/","Café Sacher"],"country":"at"}');

        $plan = app(DesignStudy::class)->plan('Eine Startseite für ein Wiener Schnitzelhaus', 'site');

        $this->assertSame('restaurant', $plan['industry']);
        $this->assertSame([], $plan['screens'], 'a page has sections, not screens');
        $this->assertSame(['figlmueller.at', 'https://plachutta.at/', 'Café Sacher'], $plan['sites']);
        $this->assertSame('at', $plan['country']);
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'bare domains'));
    }

    public function test_the_study_speaks_the_language_of_what_is_being_drawn(): void
    {
        $this->answer('Brief.');
        $ref = ['id' => 'x', 'note' => '', 'data' => 'data:image/webp;base64,AA==', 'screen_type' => 'landing page'];
        $plan = ['industry' => 'restaurant', 'screens' => [], 'apps' => [], 'sites' => ['figlmueller.at'], 'country' => 'at'];

        app(DesignStudy::class)->study('Schnitzelhaus', $plan, [$ref], '', 'site');
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'][0]['text'], 'landing page for this customer')
            && str_contains($r['messages'][0]['content'][0]['text'], 'The three sections under it'));

        app(DesignStudy::class)->study('Schnitzelhaus', $plan, [$ref], '', 'ads');
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'][0]['text'], 'five paid social creatives')
            && str_contains($r['messages'][0]['content'][0]['text'], 'the proof, the offer'));
    }

    public function test_prose_instead_of_json_is_no_plan(): void
    {
        $this->answer('I would say this is a ride-hailing app.');

        $this->assertNull(app(DesignStudy::class)->plan('App gọi xe'));
    }

    public function test_the_study_shows_every_screen_and_returns_the_brief(): void
    {
        $this->answer('Every one of these opens on a map.');
        $refs = [
            ['id' => 'a', 'note' => 'sheet over map', 'data' => 'data:image/webp;base64,AAAA', 'screen_type' => 'map'],
            ['id' => 'b', 'note' => '', 'data' => 'data:image/webp;base64,BBBB', 'screen_type' => 'store screenshot 1'],
        ];
        $plan = ['industry' => 'transport_mobility', 'screens' => ['map', 'list_feed'], 'apps' => ['Grab', 'Be'], 'country' => 'vn'];

        $brief = app(DesignStudy::class)->study('App gọi xe ở Hà Nội', $plan, $refs, '50 screens of transport mobility apps, counted:');

        $this->assertSame('Every one of these opens on a map.', $brief);
        Http::assertSent(function ($request) {
            $content = $request['messages'][0]['content'];
            $images = array_filter($content, fn ($c) => $c['type'] === 'image_url');
            $text = implode("\n", array_column(array_filter($content, fn ($c) => $c['type'] === 'text'), 'text'));

            return count($images) === 2
                && str_contains($text, 'Grab, Be')
                && str_contains($text, 'Image 1: map (sheet over map)')
                && str_contains($text, '50 screens of transport mobility')
                && str_contains($text, 'read it as data');
        });
    }

    public function test_a_sidecar_that_fails_means_no_study_not_a_failed_build(): void
    {
        Http::fake(['*/v1/chat/completions' => Http::response(null, 502)]);
        $plan = ['industry' => 'other', 'screens' => ['map'], 'apps' => [], 'country' => 'at'];

        $this->assertNull(app(DesignStudy::class)->plan('x'));
        $this->assertNull(app(DesignStudy::class)->study('x', $plan, [['id' => 'a', 'note' => '', 'data' => 'd', 'screen_type' => 'map']], ''));
    }
}
