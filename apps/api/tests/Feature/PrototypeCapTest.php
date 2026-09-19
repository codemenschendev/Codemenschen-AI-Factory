<?php

namespace Tests\Feature;

use App\Http\Controllers\PrototypeController;
use App\Jobs\BuildPrototype;
use App\Models\Customer;
use App\Models\Prototype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The free tier's daily cap.
 *
 * It counts per visitor, which only works if the framework knows who the visitor is. Behind the
 * reverse proxy every request arrived from the docker gateway, so the cap was global: five
 * prototypes a day for the whole internet, and the fifth visitor locked out the sixth.
 */
class PrototypeCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function build(array $headers = []): TestResponse
    {
        return $this->postJson('/api/prototypes', [
            'prompt' => 'Eine App für ein Friseurstudio in Wien mit Terminbuchung',
            'kind' => 'app',
            'email' => 'besucher@example.com',
        ], $headers);
    }

    /** @param string $ip fills the cap for one address */
    private function fill(string $ip, int $n = 5): void
    {
        for ($i = 0; $i < $n; $i++) {
            Prototype::create(['status' => 'ready', 'prompt' => 'x', 'ip' => $ip, 'expires_at' => now()->addDay()]);
        }
    }

    public function test_a_visitor_who_is_not_signed_in_gives_an_e_mail_and_nothing_is_built_yet(): void
    {
        $before = Customer::count();
        $this->postJson('/api/prototypes', ['prompt' => 'Eine App für ein Friseurstudio in Wien', 'kind' => 'app'])
            ->assertStatus(422)->assertJson(['code' => 'email']);

        $this->build()->assertStatus(202)->assertJson(['status' => 'waiting']);
        Queue::assertNothingPushed();
        $this->assertSame($before, Customer::count(), 'nothing is created before the link is opened');
        $this->assertSame('waiting', Prototype::sole()->status);
    }

    public function test_the_link_in_the_e_mail_signs_in_and_starts_the_build_once(): void
    {
        $this->build()->assertStatus(202);
        $proto = Prototype::sole();
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('prototypes.confirm', now()->addDay(), ['prototype' => $proto->id, 'locale' => 'de']);

        $res = $this->get($url)->assertRedirect();
        $this->assertMatchesRegularExpression('~/de/p/'.$proto->id.'#token=\S+~', $res->headers->get('Location'));
        $proto->refresh();
        $this->assertSame('queued', $proto->status);
        $this->assertSame(Customer::where('email', 'besucher@example.com')->value('id'), $proto->customer_id);
        Queue::assertPushed(BuildPrototype::class, 1);

        $this->get($url)->assertRedirect();
        Queue::assertPushed(BuildPrototype::class, 1);
        $this->get('/api/prototypes/'.$proto->id.'/confirm?locale=de')->assertForbidden();
    }

    public function test_a_signed_in_visitor_builds_at_once_even_ads(): void
    {
        $me = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de']);
        $token = $me->createToken('portal')->plainTextToken;
        $this->postJson('/api/prototypes', ['prompt' => 'Weihnachtsanzeigen für eine Bäckerei in Graz', 'kind' => 'ads'],
            ['Authorization' => 'Bearer '.$token])->assertStatus(202)->assertJson(['status' => 'queued']);
        Queue::assertPushed(BuildPrototype::class);
        $this->assertSame($me->id, Prototype::sole()->customer_id);
    }

    public function test_an_unknown_address_from_the_form_gets_a_link_that_creates_the_account(): void
    {
        $before = Customer::count();
        $this->postJson('/api/auth/magic-link', ['email' => 'Neu@Example.com', 'locale' => 'de', 'join' => true])->assertOk();
        $this->assertSame($before, Customer::count(), 'nothing is created before the link is clicked');

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('auth.join', now()->addMinutes(30), ['email' => 'neu@example.com', 'locale' => 'de']);
        $this->get($url)->assertRedirect();
        $this->assertSame($before + 1, Customer::count());
        $this->assertTrue(Customer::where('email', 'neu@example.com')->exists());
        $this->get('/api/auth/join?email=x@example.com&locale=de')->assertForbidden();
    }

    public function test_a_signed_in_visitor_gets_five_a_day(): void
    {
        $this->fill('203.0.113.7');
        $token = Customer::create(['email' => 'a@example.com', 'locale' => 'de'])->createToken('portal')->plainTextToken;

        $this->build(['X-Forwarded-For' => '203.0.113.7', 'Authorization' => 'Bearer '.$token])->assertStatus(429);
    }

    public function test_one_visitor_hitting_the_cap_does_not_block_another(): void
    {
        // The whole point of "per IP". Before trusted proxies this failed: both requests came
        // from the gateway, so the second visitor was refused for the first one's usage.
        $this->fill('203.0.113.7');

        $this->build(['X-Forwarded-For' => '198.51.100.4'])->assertStatus(202);
    }

    public function test_the_forwarded_address_is_the_one_that_is_counted(): void
    {
        $this->fill('198.51.100.4');

        $this->build(['X-Forwarded-For' => '198.51.100.4'])->assertStatus(429);
        $this->build(['X-Forwarded-For' => '198.51.100.9'])->assertStatus(202);
    }

    public function test_an_admin_is_not_capped(): void
    {
        $this->fill('198.51.100.4');
        $token = Customer::create(['email' => 'ops@example.com', 'locale' => 'de', 'is_admin' => true])
            ->createToken('portal')->plainTextToken;

        $this->build(['X-Forwarded-For' => '198.51.100.4', 'Authorization' => 'Bearer '.$token])
            ->assertStatus(202);

        Queue::assertPushed(BuildPrototype::class);
    }

    public function test_a_signed_in_customer_is_still_capped(): void
    {
        $this->fill('198.51.100.4');
        $token = Customer::create(['email' => 'kunde@example.com', 'locale' => 'de'])
            ->createToken('portal')->plainTextToken;

        $this->build(['X-Forwarded-For' => '198.51.100.4', 'Authorization' => 'Bearer '.$token])
            ->assertStatus(429);
    }

    public function test_the_prototype_records_the_real_address(): void
    {
        $this->build(['X-Forwarded-For' => '198.51.100.22'])->assertStatus(202);

        $this->assertSame('198.51.100.22', Prototype::latest()->first()->ip);
    }

    /**
     * A visitor who pastes the whole story of the shop must get a prototype, not "try again".
     * The first ceiling (1200) was hit by exactly those visitors, and the form could not say why.
     */
    public function test_a_page_long_brief_is_accepted_and_one_over_the_ceiling_names_the_field(): void
    {
        $sentence = 'Bäckerei in Salzburg mit drei Filialen, Brot, Gebäck und Vorbestellung im Onlineshop. ';
        $long = mb_substr(str_repeat($sentence, 60), 0, PrototypeController::MAX_PROMPT);

        $this->postJson('/api/prototypes', ['prompt' => $long, 'kind' => 'site', 'email' => 'b@example.com'])->assertStatus(202);

        $this->postJson('/api/prototypes', ['prompt' => $long.'x', 'kind' => 'site', 'email' => 'b@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['prompt']);
        $this->assertSame(1, Prototype::count());
    }
}
