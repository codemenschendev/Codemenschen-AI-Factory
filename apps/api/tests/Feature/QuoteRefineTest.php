<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderFulfillment;
use App\Services\Refiner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuoteRefineTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFT = 'A booking app for my dance school with class schedules and payments for members.';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.worker.url' => 'http://worker.test', 'services.worker.token' => 't']);
    }

    private function fakeWorker(int $status = 200): void
    {
        Http::fake([
            'worker.test/refine' => Http::response($status === 200 ? [
                'off_topic' => false,
                'description' => 'Members of a dance school book classes and pay online.',
                'questions' => [['q' => 'Do members need their own login?', 'options' => ['Yes', 'No']]],
                'suggested_features' => ['auth', 'pay'],
            ] : ['error' => 'gateway unavailable'], $status),
        ]);
    }

    public function test_refines_through_the_worker_and_caches_identical_drafts(): void
    {
        $this->fakeWorker();
        $this->postJson('/api/quotes/refine', ['text' => self::DRAFT, 'locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('description', 'Members of a dance school book classes and pay online.')
            ->assertJsonPath('suggested_features', ['auth', 'pay'])
            ->assertJsonPath('questions.0.options', ['Yes', 'No']);

        // Same draft (case/whitespace-insensitive) → cache, no second model call, no limit spent.
        $this->postJson('/api/quotes/refine', ['text' => '  '.strtoupper(self::DRAFT), 'locale' => 'en'])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_anonymous_visitor_gets_three_rounds_a_day(): void
    {
        $this->fakeWorker();
        for ($i = 1; $i <= Refiner::ANON_DAILY; $i++) {
            $this->postJson('/api/quotes/refine', ['text' => self::DRAFT." Variation $i."])->assertOk();
        }
        $this->postJson('/api/quotes/refine', ['text' => self::DRAFT.' One more.'])
            ->assertStatus(429)
            ->assertJsonPath('signed_in', false);
        Http::assertSentCount(Refiner::ANON_DAILY);
    }

    public function test_short_or_missing_drafts_never_reach_the_worker(): void
    {
        $this->fakeWorker();
        $this->postJson('/api/quotes/refine', ['text' => 'an app'])->assertStatus(422);
        $this->postJson('/api/quotes/refine', [])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_change_request_refine_sends_project_scope_and_returns_the_verdict(): void
    {
        Http::fake([
            'worker.test/run' => Http::response(['accepted' => 'run'], 202), // paying dispatches the first stage
            'worker.test/refine' => Http::response([
                'off_topic' => false, 'in_scope' => false,
                'scope_note' => 'Calendar sync is a new integration, not a change round.',
                'description' => 'Sync every booking to Google Calendar.',
                'questions' => [], 'suggested_features' => [],
            ]),
        ]);
        $quote = $this->postJson('/api/quotes', ['idea' => 'salon booking', 'audience' => 'b2b', 'platform' => 'mobile', 'features' => ['auth']])->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'c@example.com', 'fagg_waiver' => true, 'terms' => true]);
        $project = app(OrderFulfillment::class)->markPaid(Order::latest('created_at')->firstOrFail(), 'pi', 100, [])->fresh();
        $token = $project->customer->createToken('portal')->plainTextToken;

        // Without the customer's token: unauthenticated, nothing sent to /refine.
        $this->postJson("/api/me/projects/{$project->id}/change-requests/refine", ['text' => 'sync bookings to my google calendar please'])
            ->assertStatus(401);
        Http::assertSentCount(1); // only the /run dispatched at payment

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/me/projects/{$project->id}/change-requests/refine", ['text' => 'sync bookings to my google calendar please', 'locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('in_scope', false)
            ->assertJsonPath('scope_note', 'Calendar sync is a new integration, not a change round.');
        Http::assertSent(fn ($req) => $req->url() === 'http://worker.test/refine' && $req['mode'] === 'change' && $req['project_id'] === $project->id);
        Http::assertSent(fn ($req) => $req->url() === 'http://worker.test/refine' && $req['features'] === ['auth']);
    }

    public function test_worker_outage_is_a_503_and_costs_no_round(): void
    {
        $this->fakeWorker(503);
        $this->postJson('/api/quotes/refine', ['text' => self::DRAFT])->assertStatus(503);
        // The failed attempt did not consume the visitor's budget.
        $this->assertSame(0, (int) Cache::get('refine:ip:'.sha1('127.0.0.1').':'.now()->format('Y-m-d'), 0));
    }
}
