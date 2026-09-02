<?php

namespace Tests\Feature;

use App\Jobs\RenderProjectAd;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectAd;
use App\Services\OrderFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The operator lane sees everything and the customer lane sees nothing of it. That boundary is the
 * point of these tests: an admin route that quietly works for a normal customer would hand every
 * customer the whole factory.
 */
class AdminTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private string $adminToken;

    private string $customerToken;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => null, 'services.worker.token' => 't']);
        Http::fake(['*/run' => Http::response(['accepted' => true], 202)]);

        $quote = $this->postJson('/api/quotes', [
            'idea' => 'club app', 'audience' => 'b2b', 'platform' => 'mobile', 'features' => [],
        ])->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'kunde@example.com', 'fagg_waiver' => true]);
        $order = Order::firstOrFail();
        $this->project = app(OrderFulfillment::class)->markPaid($order, 'pi', 100, []);
        $this->customerToken = $order->customer->createToken('portal')->plainTextToken;

        $admin = Customer::create(['email' => 'admin@example.com', 'locale' => 'de', 'is_admin' => true]);
        $this->adminToken = $admin->createToken('portal')->plainTextToken;
    }

    private function asAdmin(): array
    {
        return ['Authorization' => 'Bearer '.$this->adminToken];
    }

    public function test_the_founding_admin_exists_and_is_flagged(): void
    {
        $this->assertTrue(Customer::where('email', 'developerweb@codemenschen.at')->firstOrFail()->isAdmin());
    }

    public function test_a_customer_cannot_reach_the_operator_lane(): void
    {
        $auth = ['Authorization' => 'Bearer '.$this->customerToken];

        $this->getJson('/api/admin/overview', $auth)->assertForbidden();
        $this->getJson('/api/admin/projects', $auth)->assertForbidden();
        $this->getJson('/api/admin/customers', $auth)->assertForbidden();
        $this->postJson("/api/admin/projects/{$this->project->id}/status", ['status' => 'READY'], $auth)->assertForbidden();
    }

    public function test_the_operator_lane_needs_a_login_at_all(): void
    {
        $this->getJson('/api/admin/overview')->assertUnauthorized();
    }

    public function test_the_overview_counts_the_whole_factory_not_the_admins_own_projects(): void
    {
        $res = $this->getJson('/api/admin/overview', $this->asAdmin())->assertOk();

        $this->assertSame(1, $res->json('projects.total'));
        $this->assertSame(1, $res->json('revenue.paid_orders'));
        // the customer, this test's admin, and developerweb@codemenschen.at from the migration
        $this->assertSame(3, $res->json('customers'));
    }

    public function test_a_failed_run_shows_up_in_the_attention_list_with_its_project(): void
    {
        PipelineRun::create([
            'project_id' => $this->project->id, 'stage' => 'coding', 'attempt' => 3,
            'status' => 'failed', 'error' => 'boom', 'finished_at' => now(), 'callback_token' => 'x',
        ]);

        $res = $this->getJson('/api/admin/overview', $this->asAdmin())->assertOk();

        $items = collect($res->json('attention'))->where('kind', 'run_failed');
        $this->assertCount(1, $items);
        $this->assertSame('kunde@example.com', $items->first()['customer']);
        $this->assertSame('coding', $items->first()['stage']);
    }

    public function test_projects_can_be_searched_by_customer_address(): void
    {
        $this->getJson('/api/admin/projects?q=kunde@example.com', $this->asAdmin())
            ->assertOk()->assertJsonCount(1, 'projects');
        $this->getJson('/api/admin/projects?q=niemand@example.com', $this->asAdmin())
            ->assertOk()->assertJsonCount(0, 'projects');
    }

    public function test_the_operator_can_dispatch_a_stage_but_not_while_one_runs(): void
    {
        // A paid project is already running its first stage; the point here is the second one.
        PipelineRun::where('project_id', $this->project->id)->update(['status' => 'succeeded', 'finished_at' => now()]);

        $this->postJson("/api/admin/projects/{$this->project->id}/stage", ['stage' => 'test'], $this->asAdmin())
            ->assertStatus(202)->assertJsonPath('stage', 'test');

        PipelineRun::where('project_id', $this->project->id)->latest()->first()->update(['status' => 'running']);

        $this->postJson("/api/admin/projects/{$this->project->id}/stage", ['stage' => 'test'], $this->asAdmin())
            ->assertStatus(409);
    }

    public function test_a_forced_status_is_written_to_the_event_log_with_the_operators_name(): void
    {
        $this->postJson("/api/admin/projects/{$this->project->id}/status", ['status' => 'REVIEW', 'reason' => 'stuck'], $this->asAdmin())
            ->assertOk()->assertJsonPath('status', 'REVIEW');

        // Ordered by id, not by created_at: the pipeline wrote its own status events in the same second.
        $event = $this->project->events()->where('type', 'status.changed')->orderByDesc('id')->first();
        $this->assertTrue($event->payload['forced']);
        $this->assertSame('admin:admin@example.com', $event->actor);
    }

    public function test_re_rendering_an_ad_drops_the_old_scenes_and_queues_it_again(): void
    {
        Queue::fake();
        $ad = ProjectAd::create([
            'project_id' => $this->project->id, 'kind' => 'video', 'name' => 'x', 'status' => 'failed',
            'source' => 'ai', 'prompt' => 'Ad for a salon', 'error' => 'boom',
            'spec' => ['size' => '1080x1920', 'goal' => 'booking', 'scenes' => [['title' => 'alt']]],
        ]);

        $this->postJson("/api/admin/ads/{$ad->id}/rerender", [], $this->asAdmin())->assertStatus(202);

        $ad->refresh();
        $this->assertSame('queued', $ad->status);
        $this->assertNull($ad->error);
        $this->assertArrayNotHasKey('scenes', $ad->spec);
        $this->assertSame('booking', $ad->spec['goal']);
        Queue::assertPushed(RenderProjectAd::class);
    }

    /* The two halves are separate tests on purpose: the auth guard caches the user it resolved, so
       two differently authenticated requests in one test method both answer as the first one. */

    public function test_the_portal_is_told_that_an_admin_is_an_admin(): void
    {
        $this->getJson('/api/me/projects', $this->asAdmin())->assertOk()->assertJsonPath('admin', true);
    }

    public function test_the_portal_is_told_that_a_customer_is_not(): void
    {
        $this->getJson('/api/me/projects', ['Authorization' => 'Bearer '.$this->customerToken])
            ->assertOk()->assertJsonPath('admin', false);
    }
}
