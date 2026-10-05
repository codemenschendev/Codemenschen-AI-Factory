<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Project;
use App\Services\CareService;
use App\Services\CreditService;
use App\Services\OrderFulfillment;
use App\Services\PipelineOrchestrator;
use App\Support\MailLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The customer console (config/console.php): change credits, Care's monthly allowance, the console's address. */
class ConsoleTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['accepted' => 'run'], 202)]);
        config(['services.stripe.secret' => null]);
    }

    private function releasedProject(string $brand = 'appmitki'): Project
    {
        $quote = $this->postJson('/api/quotes', ['idea' => 'club app', 'audience' => 'b2b', 'platform' => 'mobile', 'features' => ['auth']])->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'c@example.com', 'fagg_waiver' => true, 'terms' => true]);
        $order = Order::latest('created_at')->firstOrFail();
        $project = app(OrderFulfillment::class)->markPaid($order, 'pi', 100, [])->fresh();
        $order->update(['brand' => $brand]);
        $project->update(['status' => 'READY']);
        $project->builds()->create(['platform' => 'bundle', 'version' => '0.1.0']);
        $this->token = $project->customer->createToken('portal')->plainTextToken;

        return $project->fresh();
    }

    public function test_a_change_credit_pays_for_a_round_and_is_used_up(): void
    {
        $project = $this->releasedProject();
        $orchestrator = app(PipelineOrchestrator::class);
        $this->assertSame('paid', $orchestrator->changeRequestMode($project));

        $project->customer->update(['edit_credits' => 1]);
        $this->withHeader('Authorization', "Bearer {$this->token}")->getJson("/api/me/projects/{$project->id}")
            ->assertJsonPath('change_request_mode', 'credit')->assertJsonPath('edit_credits', 1);
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/me/projects/{$project->id}/change-requests", ['text' => 'Please make the primary button green.'])
            ->assertCreated()->assertJsonPath('status', 'FIXING');

        $this->assertSame('credit', $project->changeRequests()->first()->covered_by);
        $this->assertSame(0, $project->customer->fresh()->edit_credits);
    }

    public function test_sofabuilt_care_covers_a_few_changes_a_month_and_appmitki_care_all(): void
    {
        config(['console.care_edits_per_month' => 1]);
        $project = $this->releasedProject('sofabuilt');
        app(CareService::class)->activate($project, 'sub_1', ['id' => 'evt_1']);
        $orchestrator = app(PipelineOrchestrator::class);
        $this->assertSame('care', $orchestrator->changeRequestMode($project->fresh()));
        $project->changeRequests()->create(['round' => 1, 'text' => 'x', 'covered_by' => 'care', 'status' => 'done']);
        $this->assertSame('paid', $orchestrator->changeRequestMode($project->fresh()), 'allowance used up');

        $project->order->update(['brand' => 'appmitki']);
        $this->assertSame('care', $orchestrator->changeRequestMode($project->fresh()), 'Appmitki Care stays unlimited');
    }

    public function test_a_paid_pack_is_credited_once(): void
    {
        $project = $this->releasedProject();
        DB::table('credit_purchases')->insert(['customer_id' => $project->customer_id, 'edits' => 5, 'eur' => 45, 'stripe_session_id' => 'cs_1', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        app(CreditService::class)->complete('cs_1');
        app(CreditService::class)->complete('cs_1');
        $this->assertSame(5, $project->customer->fresh()->edit_credits);

        $this->withHeader('Authorization', "Bearer {$this->token}")->postJson('/api/me/credits/checkout', ['edits' => 7])->assertUnprocessable();
        $this->withHeader('Authorization', "Bearer {$this->token}")->postJson('/api/me/credits/checkout', ['edits' => 5])->assertStatus(503);
    }

    public function test_customer_pages_move_to_the_console_when_it_is_set_up(): void
    {
        config(['services.frontend_url' => 'https://appmitki.com', 'console.url' => '']);
        $this->assertSame('https://appmitki.com/en/account', MailLink::portal('/en/account'));
        config(['console.url' => 'https://console.appmitki.com']);
        $this->assertSame('https://console.appmitki.com/de/account/x', MailLink::portal('/de/account/x'));
        $this->assertSame('https://appmitki.com/de/admin', MailLink::portal('/de/admin'), 'the admin console stays on the storefront');
    }
}
