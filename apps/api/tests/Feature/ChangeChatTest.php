<?php

namespace Tests\Feature;

use App\Models\ChangeMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Services\ChangeChat;
use App\Services\OrderFulfillment;
use App\Services\PipelineOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The change chat (docs/specs/change-chat.md, section 8): talking is free, only a confirmed
 * summary card starts a round, and the round reports back into the same thread.
 */
class ChangeChatTest extends TestCase
{
    use RefreshDatabase;

    /** Replies the fake assistant gives, in order. */
    private array $replies = [];

    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.stripe.secret' => null, 'services.worker.token' => 't', 'queue.default' => 'sync',
            'services.change_chat.enabled' => true, 'services.buzz.alert_dir' => null, 'services.openclaw.hook_url' => null,
        ]);
        Http::fake([
            '*/run' => Http::response(['accepted' => true], 202),
            '*/change-chat' => function ($request) {
                $this->asked[] = $request->data();

                $reply = array_shift($this->replies) ?? ['reply' => 'ok', 'scope' => 'in'];

                return $reply === 'down' ? Http::response(['error' => 'down'], 502) : Http::response($reply);
            },
        ]);
    }

    private function reviewedProject(): Project
    {
        $quote = $this->postJson('/api/quotes', [
            'idea' => 'club app', 'audience' => 'b2b', 'platform' => 'mobile', 'features' => ['auth'],
        ])->json('id');
        $this->postJson('/api/checkout', ['quote_id' => $quote, 'email' => 'c@example.com', 'fagg_waiver' => true, 'terms' => true]);
        $project = app(OrderFulfillment::class)->markPaid(Order::latest('created_at')->firstOrFail(), 'pi', 100, [])->fresh();
        $project->update(['status' => 'REVIEW']);
        $project->criteria()->create(['key' => 'boots', 'criterion' => 'app boots', 'kind' => 'automated', 'status' => 'passed']);
        $project->builds()->create(['platform' => 'bundle', 'version' => '0.1.0']);

        return $project->fresh();
    }

    private function as(Project $project): array
    {
        // Sanctum keeps the last request's user between requests in one test; each call is its own visitor.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$project->customer->createToken('portal')->plainTextToken];
    }

    private function card(array $items = ['Button "Termin buchen" at least 48 px high', 'Button in brand green #0A5C2B']): array
    {
        return ['reply' => 'Die Zusammenfassung ist bereit.', 'items' => array_map(fn ($t) => ['text' => $t], $items), 'scope' => 'in'];
    }

    private function completeStage(Project $project, string $stage, array $output = [], string $status = 'succeeded'): void
    {
        $run = PipelineRun::where('project_id', $project->id)->where('stage', $stage)
            ->where('status', 'running')->latest('created_at')->firstOrFail();
        $token = $run->getAttributes()['callback_token'];
        $this->postJson("/api/internal/runs/{$run->id}/complete", [
            'status' => $status, 'output' => $output, 'error' => $status === 'failed' ? 'boom' : null,
        ], ['Authorization' => "Bearer $token"])->assertOk();
    }

    public function test_talking_asks_questions_and_never_starts_a_round(): void
    {
        $project = $this->reviewedProject();
        $this->replies = [[
            'reply' => 'Gern. Welcher Teil?', 'scope' => 'in',
            'questions' => [['q' => 'Welcher Teil?', 'options' => ['Nur der Button', 'Die ganze Seite']]],
            // Items next to open questions are ignored: ask first, then summarise.
            'items' => [['text' => 'something']],
        ]];

        $res = $this->withHeaders($this->as($project))
            ->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Der Button ist zu klein'])
            ->assertCreated();

        $this->assertSame(['customer', 'assistant'], array_column($res->json('messages'), 'role'));
        $this->assertSame('Welcher Teil?', $res->json('messages.1.meta.questions.0.q'));
        $this->assertArrayNotHasKey('card', $res->json('messages.1.meta'));
        $this->assertSame('customer', $this->asked[0]['transcript'][0]['role']);
        $this->assertStringContainsString('German', $this->asked[0]['system']);

        $project = $project->fresh();
        $this->assertSame(0, $project->revision_rounds);
        $this->assertSame(0, $project->changeRequests()->count());
        $this->assertSame('REVIEW', $project->status);
    }

    public function test_a_confirmed_card_becomes_the_change_request_verbatim_and_reports_back(): void
    {
        $project = $this->reviewedProject();
        $headers = $this->as($project);
        $this->replies = [$this->card()];
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Nur der Button, größer und grün'])
            ->assertCreated()
            ->assertJsonPath('messages.1.meta.type', 'card')
            ->assertJsonPath('messages.1.meta.card.mode', 'free')
            ->assertJsonPath('messages.1.meta.card.round', 1);

        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages/confirm")
            ->assertCreated()->assertJsonPath('status', 'in_progress');

        $cr = $project->changeRequests()->firstOrFail();
        $this->assertSame("1. Button \"Termin buchen\" at least 48 px high\n2. Button in brand green #0A5C2B", $cr->text);
        $this->assertCount(2, $cr->items);
        $this->assertSame('FIXING', $project->fresh()->status);
        $this->assertSame(0, ChangeMessage::whereNull('change_request_id')->where('role', '!=', 'system')->count(), 'the draft belongs to the round now');

        // The revise job carries the checklist to the worker.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/run') && ($r['context']['change_items'][1]['text'] ?? null) === 'Button in brand green #0A5C2B');

        $this->completeStage($project, 'revise', ['done' => true, 'summary' => 'Button größer — und grün.', 'items' => [
            ['text' => 'Button "Termin buchen" at least 48 px high', 'done' => true, 'note' => '52 px'],
            ['text' => 'Button in brand green #0A5C2B', 'done' => false, 'note' => 'colour kept for contrast'],
        ]]);
        $this->completeStage($project, 'test', ['report' => ['passed' => 1, 'failed' => 0], 'criteria_results' => ['boots' => 'passed']]);
        $this->completeStage($project, 'release', ['builds' => [['platform' => 'bundle', 'version' => '0.1.1', 'artifact_path' => 'x/b.tar.gz']]]);

        $this->assertSame('REVIEW', $project->fresh()->status);
        $thread = $this->withHeaders($headers)->getJson("/api/me/projects/{$project->id}/messages")->assertOk()->json('messages');
        $types = array_values(array_filter(array_map(fn ($m) => $m['meta']['type'] ?? null, $thread)));
        $this->assertSame(['card', 'started', 'result'], $types);
        $result = end($thread);
        $this->assertFalse($result['meta']['items'][1]['done'], 'an item the agent did not do is shown as not done');
        $this->assertStringNotContainsString('—', $result['meta']['summary']);
        $this->assertStringNotContainsString('—', $project->changeRequests()->first()->agent_summary);
    }

    public function test_a_paid_round_needs_the_waiver_and_goes_to_checkout(): void
    {
        $project = $this->reviewedProject();
        $project->update(['revision_rounds' => 3]);
        foreach ([1, 2, 3] as $round) {
            $project->changeRequests()->create(['round' => $round, 'text' => 'old', 'status' => 'done']);
        }
        $headers = $this->as($project);
        $this->replies = [$this->card()];
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Button grün bitte'])
            ->assertJsonPath('messages.1.meta.card.mode', 'paid')
            ->assertJsonPath('messages.1.meta.card.price_eur', 39);

        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages/confirm")->assertStatus(422);
        $this->assertSame(3, $project->changeRequests()->count());

        // Stripe is not configured in tests: the request is created and waits for payment.
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages/confirm", ['fagg_waiver' => true]);
        $cr = $project->changeRequests()->latest('id')->first();
        $this->assertSame('awaiting_payment', $cr->status);
        $this->assertSame('REVIEW', $project->fresh()->status);
    }

    public function test_out_of_scope_is_answered_without_a_change_request(): void
    {
        $project = $this->reviewedProject();
        $this->replies = [['reply' => 'Ein Kalender-Sync ist eine neue Funktion.', 'scope' => 'out', 'reason' => 'new integration', 'items' => [['text' => 'x']]]];

        $this->withHeaders($this->as($project))
            ->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Bitte Google Kalender anbinden'])
            ->assertCreated()->assertJsonPath('messages.1.meta.type', 'declined');

        $this->assertSame(0, $project->changeRequests()->count());
        $this->withHeaders($this->as($project))->postJson("/api/me/projects/{$project->id}/messages/confirm")->assertStatus(409);
    }

    public function test_a_card_the_conversation_moved_past_cannot_be_confirmed(): void
    {
        $project = $this->reviewedProject();
        $headers = $this->as($project);
        $this->replies = [$this->card(), ['reply' => 'Welche Farbe genau?', 'scope' => 'in']];
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Button grün']);
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Moment, doch eine andere Farbe']);

        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages/confirm")->assertStatus(409);
        $this->assertSame(0, $project->changeRequests()->count());
    }

    public function test_a_declined_round_posts_into_the_thread(): void
    {
        $project = $this->reviewedProject();
        $headers = $this->as($project);
        $this->replies = [$this->card(['Add video calls'])];
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Videoanrufe']);
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages/confirm")->assertCreated();

        $this->completeStage($project, 'revise', ['done' => true, 'declined' => 'Video calls are a new feature.']);

        $last = ChangeMessage::latest('id')->first();
        $this->assertSame('declined', $last->meta['type']);
        $this->assertSame('Video calls are a new feature.', $last->meta['reason']);
        $this->assertSame('REVIEW', $project->fresh()->status);
    }

    public function test_a_failed_round_tells_the_customer(): void
    {
        $project = $this->reviewedProject();
        $headers = $this->as($project);
        $this->replies = [$this->card()];
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Button grün']);
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages/confirm")->assertCreated();

        $orchestrator = app(PipelineOrchestrator::class);
        $run = $project->runs()->where('stage', 'revise')->latest('created_at')->first();
        $run->update(['attempt' => 3]);
        $this->completeStage($project, 'revise', [], 'failed');

        $this->assertSame('failed', ChangeMessage::latest('id')->first()->meta['type'] ?? null);
    }

    public function test_another_customer_cannot_read_or_write_the_thread(): void
    {
        $project = $this->reviewedProject();
        $stranger = Customer::create(['email' => 'x@example.com', 'locale' => 'de']);
        $headers = ['Authorization' => 'Bearer '.$stranger->createToken('portal')->plainTextToken];

        $this->withHeaders($headers)->getJson("/api/me/projects/{$project->id}/messages")->assertNotFound();
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'hi'])->assertNotFound();
        $this->assertSame(0, ChangeMessage::count());
    }

    public function test_the_daily_limit_keeps_the_message_and_says_a_person_will_answer(): void
    {
        $project = $this->reviewedProject();
        Cache::put("change-chat:customer:{$project->customer_id}:".now()->format('Y-m-d'), ChangeChat::DAILY_REPLIES, now()->addDay());

        $this->withHeaders($this->as($project))
            ->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Noch eine Frage'])
            ->assertCreated()->assertJsonPath('messages.1.meta.type', 'limit');

        $this->assertSame([], $this->asked, 'no assistant call past the limit');
        $this->assertSame('Noch eine Frage', ChangeMessage::where('role', 'customer')->first()->body);
    }

    public function test_an_operator_replies_and_pauses_the_assistant(): void
    {
        $project = $this->reviewedProject();
        $admin = Customer::create(['email' => 'ops@example.com', 'locale' => 'de', 'is_admin' => true]);
        $adminToken = $admin->createToken('portal')->plainTextToken;
        $adminHeaders = ['Authorization' => 'Bearer '.$adminToken];

        $this->withHeaders($adminHeaders)->postJson("/api/admin/projects/{$project->id}/assistant", ['paused' => true])
            ->assertOk()->assertJsonPath('assistant_paused', true);
        $this->withHeaders($this->as($project))->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Hallo?'])
            ->assertCreated();
        $this->assertSame([], $this->asked, 'a paused assistant does not answer');

        $this->app['auth']->forgetGuards();
        $this->withHeaders($adminHeaders)->postJson("/api/admin/projects/{$project->id}/messages", ['body' => 'Hallo, hier ist Patrick.'])
            ->assertCreated();
        $this->withHeaders($this->as($project))->getJson("/api/me/projects/{$project->id}/messages")
            ->assertJsonPath('messages.1.role', 'operator')
            ->assertJsonPath('messages.1.body', 'Hallo, hier ist Patrick.');

        $this->withHeaders($this->as($project))->getJson("/api/admin/projects/{$project->id}/messages")->assertForbidden();
    }

    public function test_the_chat_stays_hidden_from_customers_until_enabled_but_admins_see_it(): void
    {
        config(['services.change_chat.enabled' => false]);
        $project = $this->reviewedProject();

        $this->withHeaders($this->as($project))->getJson("/api/me/projects/{$project->id}")->assertJsonPath('change_chat', false);
        $this->withHeaders($this->as($project))->getJson("/api/me/projects/{$project->id}/messages")->assertNotFound();

        $project->customer->update(['is_admin' => true]);
        $this->withHeaders($this->as($project))->getJson("/api/me/projects/{$project->id}")->assertJsonPath('change_chat', true);
    }

    public function test_the_thread_speaks_the_language_of_the_order_not_of_the_customer_record(): void
    {
        $project = $this->reviewedProject();
        $project->order->update(['locale' => 'de']);
        $project->customer->update(['locale' => 'en']);
        $this->replies = [$this->card()];
        $headers = $this->as($project->fresh());
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Button grün']);
        $this->withHeaders($headers)->postJson("/api/me/projects/{$project->id}/messages/confirm")->assertCreated();

        $this->assertStringContainsString('German', $this->asked[0]['system']);
        $this->assertStringStartsWith('Wird umgesetzt', ChangeMessage::latest('id')->first()->body);
    }

    public function test_assistant_text_loses_its_dashes(): void
    {
        $project = $this->reviewedProject();
        $this->replies = [['reply' => 'Gern — ich frage kurz nach – welcher Button?', 'scope' => 'in']];

        $res = $this->withHeaders($this->as($project))->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Button']);

        $this->assertSame('Gern, ich frage kurz nach, welcher Button?', $res->json('messages.1.body'));
    }

    public function test_an_assistant_that_is_down_keeps_the_message(): void
    {
        $project = $this->reviewedProject();
        $this->replies = ['down'];

        $this->withHeaders($this->as($project))->postJson("/api/me/projects/{$project->id}/messages", ['body' => 'Button'])
            ->assertStatus(503)->assertJsonPath('assistant', 'unavailable');
        $this->assertSame(1, ChangeMessage::where('role', 'customer')->count());
    }
}
