<?php

namespace App\Services;

use App\Domain\Ai\Prompts;
use App\Domain\Pricing\Estimator;
use App\Models\ChangeMessage;
use App\Models\ChangeRequest;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The change chat on the project page (docs/specs/change-chat.md).
 *
 * The customer talks with an assistant until the change is concrete, then confirms a summary card.
 * Only that confirmation starts a round, through the same PipelineOrchestrator::requestChanges the
 * old form used, so rounds, payment, FAGG consent and Care work exactly as before. Talking is free.
 *
 * The assistant runs in the worker next to the project repository (it reads SPEC.md there) and has
 * no tools: it writes a reply, at most two questions, and a checklist once the change is clear.
 * What the pipeline does afterwards is posted into the thread as system messages.
 */
class ChangeChat
{
    /** Assistant replies a customer gets per day, over all their projects. */
    public const DAILY_REPLIES = 40;

    /** Assistant replies within one draft. A longer draft is a conversation for a person. */
    public const DRAFT_REPLIES = 20;

    /** Circuit breaker for the whole portal per day. */
    public const GLOBAL_DAILY = 500;

    private const MAX_ITEMS = 8;

    public function __construct(private PipelineOrchestrator $orchestrator, private Notify $notify) {}

    public static function enabledFor(?Customer $customer): bool
    {
        return (bool) config('services.change_chat.enabled') || (bool) $customer?->is_admin;
    }

    /** The thread, oldest first. `$after` returns only newer messages, for polling. */
    public function thread(Project $project, int $after = 0): array
    {
        return $project->changeMessages()->where('id', '>', $after)->orderBy('id')->limit(300)->get()
            ->map(fn (ChangeMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'body' => $m->body,
                'meta' => $m->meta ?? (object) [],
                'change_request_id' => $m->change_request_id,
                'created_at' => $m->created_at->toIso8601String(),
            ])->all();
    }

    /**
     * A customer message and, unless the assistant is paused or out of quota, its reply.
     *
     * @return array{status:int, messages:list<ChangeMessage>}
     */
    public function customerSays(Project $project, Customer $customer, string $body): array
    {
        $mine = $this->add($project, 'customer', $body);

        if ($project->assistant_paused) {
            $this->notify->alert($project, 'change chat: customer wrote while the assistant is paused: '.mb_substr($body, 0, 200));

            return ['status' => 201, 'messages' => [$mine]];
        }

        $day = now()->format('Y-m-d');
        $customerKey = "change-chat:customer:{$customer->id}:{$day}";
        if ((int) Cache::get($customerKey, 0) >= self::DAILY_REPLIES
            || $this->draftReplies($project) >= self::DRAFT_REPLIES
            || (int) Cache::get("change-chat:global:{$day}", 0) >= self::GLOBAL_DAILY) {
            $limit = $this->add($project, 'system', $this->say($customer, 'limit'), ['type' => 'limit']);
            $this->notify->alert($project, 'change chat: limit reached, the customer waits for a person');

            return ['status' => 201, 'messages' => [$mine, $limit]];
        }

        $out = $this->ask($project, $customer);
        if ($out === null) {
            return ['status' => 503, 'messages' => [$mine]];
        }
        $this->count($customerKey);
        $this->count("change-chat:global:{$day}");

        $meta = [];
        if ($out['questions']) {
            $meta['questions'] = $out['questions'];
        }
        $mode = $this->orchestrator->changeRequestMode($project);
        if ($out['scope'] === 'out') {
            $meta['type'] = 'declined';
            $this->notify->alert($project, 'change chat: out of scope before a round: '.mb_substr($out['reason'] ?: $body, 0, 200));
        } elseif ($out['items'] && $mode !== 'none') {
            $meta['type'] = 'card';
            $meta['card'] = [
                'items' => $out['items'],
                'mode' => $mode,
                'round' => $project->revision_rounds + 1,
                'price_eur' => $mode === 'paid' ? Estimator::REVISION_PRICE_EUR : 0,
                'free_rounds_left' => $this->orchestrator->freeRoundsLeft($project),
            ];
        }
        if ($out['scope'] === 'borderline' && $this->borderlineInDraft($project) >= 1) {
            $this->notify->alert($project, 'change chat: borderline scope twice in one draft, worth a look');
        }
        if ($out['scope'] === 'borderline') {
            $meta['scope'] = 'borderline';
        }

        $reply = $this->add($project, 'assistant', $out['reply'], $meta ?: null);

        return ['status' => 201, 'messages' => [$mine, $reply]];
    }

    /**
     * The customer pressed "Umsetzen" on the newest summary card. Creates the change request with
     * the card's items as its text, verbatim, and ties the draft to it.
     */
    public function confirm(Project $project, Customer $customer, bool $faggWaiver, ?string $ip): ChangeRequest
    {
        $card = $project->changeMessages()->whereNull('change_request_id')->where('role', 'assistant')
            ->orderByDesc('id')->get()->first(fn (ChangeMessage $m) => ($m->meta['type'] ?? null) === 'card');
        abort_unless($card, 409, 'There is no summary to confirm.');
        $newer = $project->changeMessages()->where('id', '>', $card->id)->whereIn('role', ['customer', 'assistant'])->exists();
        abort_if($newer, 409, 'The conversation moved on after this summary.');

        $items = array_values(array_map(fn ($i) => ['text' => (string) $i['text']], $card->meta['card']['items']));
        $text = self::briefText($items);
        $cr = $this->orchestrator->requestChanges($project, $text, 'customer:'.$customer->email, $faggWaiver, $ip, $items);

        $project->changeMessages()->whereNull('change_request_id')->where('id', '<=', $card->id)
            ->update(['change_request_id' => $cr->id]);
        $card->update(['meta' => array_merge($card->meta, ['confirmed' => true])]);

        $cr = $cr->fresh();
        if ($cr->status === 'awaiting_payment') {
            $this->add($project, 'system', $this->say($customer, 'payment'), ['type' => 'payment', 'checkout_url' => $cr->checkout_url], $cr);
        } else {
            $this->add($project, 'system', $this->say($customer, 'started'), ['type' => 'started', 'round' => $cr->round], $cr);
        }

        return $cr;
    }

    public function operatorSays(Project $project, string $email, string $body): ChangeMessage
    {
        return $this->add($project, 'operator', $body, null, null, $email);
    }

    /* ---------------- pipeline events, called from PipelineOrchestrator ---------------- */

    public function onPaid(ChangeRequest $cr): void
    {
        if ($cr->items !== null) {
            $this->post($cr, 'paid');
        }
    }

    /** declined (out_of_scope) and failed are final; done waits for the preview. */
    public function onClosed(ChangeRequest $cr): void
    {
        if ($cr->items === null) {
            return; // sent through the old form: no thread to post into
        }
        if ($cr->status === 'out_of_scope') {
            $this->post($cr, 'declined', ['reason' => (string) $cr->agent_summary]);
            $this->notify->alert($cr->project, "change chat: round {$cr->round} declined by the agent");
        } elseif ($cr->status === 'failed') {
            $this->post($cr, 'failed');
            $this->notify->alert($cr->project, "change chat: round {$cr->round} failed");
        }
    }

    /** The project reached REVIEW with a fresh preview, or failed its tests after a round. */
    public function onProjectSettled(Project $project): void
    {
        $cr = $project->changeRequests()->where('status', 'done')->latest('id')->first();
        if (! $cr || $cr->items === null || $this->posted($cr, ['result', 'failed'])) {
            return;
        }
        if ($project->status === 'FAILED') {
            $this->post($cr, 'failed');
            $this->notify->alert($project, "change chat: tests failed after round {$cr->round}");

            return;
        }
        $this->post($cr, 'result', [
            'summary' => (string) $cr->agent_summary,
            'items' => $cr->result_items ?? [],
            'preview_url' => $project->previewUrl(),
        ]);
    }

    /* ---------------- internals ---------------- */

    /** @param list<array{text:string}> $items */
    public static function briefText(array $items): string
    {
        $lines = [];
        foreach ($items as $n => $item) {
            $lines[] = ($n + 1).'. '.$item['text'];
        }

        return implode("\n", $lines);
    }

    /**
     * Spaced dashes as sentence breaks read like a machine wrote them, and customer copy must not
     * have them. Hyphens inside words stay.
     */
    public static function undash(string $text): string
    {
        $text = preg_replace('/\s+[—–]\s+/u', ', ', $text);

        return str_replace('—', ', ', $text);
    }

    /** @return array{reply:string, questions:list<array{q:string,options:list<string>}>, items:list<array{text:string}>, scope:string, reason:string}|null */
    private function ask(Project $project, Customer $customer): ?array
    {
        $draft = $project->changeMessages()->whereNull('change_request_id')->orderByDesc('id')->limit(20)->get()->reverse();
        $transcript = $draft->map(fn (ChangeMessage $m) => [
            'role' => $m->role,
            'body' => mb_substr($m->body, 0, 2000),
            'card' => ($m->meta['type'] ?? null) === 'card' ? array_column($m->meta['card']['items'], 'text') : null,
        ])->values()->all();

        $recent = $project->changeRequests()->latest('id')->limit(3)->get()
            ->map(fn (ChangeRequest $cr) => "Round {$cr->round} ({$cr->status}): ".mb_substr($cr->text, 0, 400)
                .($cr->agent_summary ? ' => '.mb_substr($cr->agent_summary, 0, 300) : ''))->implode("\n");
        $mode = $this->orchestrator->changeRequestMode($project);

        try {
            $res = Http::timeout(90)
                ->withToken(config('services.worker.token'))
                ->post(rtrim(config('services.worker.url'), '/').'/change-chat', [
                    'system' => Prompts::get('change/assistant', [
                        'language' => $customer->locale === 'en' ? 'English' : 'German',
                        'status' => $project->status,
                        'mode' => $mode,
                        'recent' => $recent ?: 'none',
                    ]),
                    'transcript' => $transcript,
                    'project_id' => $project->id,
                    'features' => array_values((array) ($project->order?->quote?->features ?? [])),
                ]);
        } catch (\Throwable $e) {
            Log::warning('change_chat.worker_failed', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $res->ok()) {
            Log::warning('change_chat.worker_failed', ['status' => $res->status()]);

            return null;
        }

        $reply = self::undash(trim((string) $res->json('reply', '')));
        if ($reply === '') {
            return null;
        }
        $questions = [];
        foreach (array_slice((array) $res->json('questions', []), 0, 2) as $q) {
            $options = array_values(array_filter(array_map(fn ($o) => self::undash(trim((string) $o)), (array) ($q['options'] ?? []))));
            if (trim((string) ($q['q'] ?? '')) !== '' && count($options) >= 2) {
                $questions[] = ['q' => self::undash(trim((string) $q['q'])), 'options' => array_slice($options, 0, 4)];
            }
        }
        $items = [];
        foreach (array_slice((array) $res->json('items', []), 0, self::MAX_ITEMS) as $item) {
            $text = self::undash(trim((string) (is_array($item) ? ($item['text'] ?? '') : $item)));
            if ($text !== '') {
                $items[] = ['text' => mb_substr($text, 0, 300)];
            }
        }
        $scope = in_array($res->json('scope'), ['in', 'borderline', 'out'], true) ? $res->json('scope') : 'in';

        return [
            'reply' => mb_substr($reply, 0, 2000),
            'questions' => $questions,
            // A card with open questions is not a card: ask first.
            'items' => $questions || $scope === 'out' ? [] : $items,
            'scope' => $scope,
            'reason' => self::undash(trim((string) $res->json('reason', ''))),
        ];
    }

    private function add(Project $project, string $role, string $body, ?array $meta = null, ?ChangeRequest $cr = null, ?string $author = null): ChangeMessage
    {
        return $project->changeMessages()->create([
            'role' => $role,
            'body' => $body,
            'meta' => $meta,
            'change_request_id' => $cr?->id,
            'author' => $author,
        ]);
    }

    private function post(ChangeRequest $cr, string $type, array $extra = []): void
    {
        $customer = $cr->project->customer;
        $this->add($cr->project, 'system', $this->say($customer, $type), array_merge(['type' => $type, 'round' => $cr->round], $extra), $cr);
    }

    /** @param list<string> $types */
    private function posted(ChangeRequest $cr, array $types): bool
    {
        return ChangeMessage::where('change_request_id', $cr->id)->where('role', 'system')->get()
            ->contains(fn (ChangeMessage $m) => in_array($m->meta['type'] ?? null, $types, true));
    }

    private function draftReplies(Project $project): int
    {
        return $project->changeMessages()->whereNull('change_request_id')->where('role', 'assistant')->count();
    }

    private function borderlineInDraft(Project $project): int
    {
        return $project->changeMessages()->whereNull('change_request_id')->where('role', 'assistant')->get()
            ->filter(fn (ChangeMessage $m) => ($m->meta['scope'] ?? null) === 'borderline')->count();
    }

    private function count(string $key): void
    {
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key);
    }

    /** System lines, in the customer's language. Short sentences, no dashes. */
    private function say(?Customer $customer, string $type): string
    {
        $de = [
            'limit' => 'Für heute ist das Kontingent erreicht. Deine Nachricht ist gespeichert, unser Team meldet sich bei dir.',
            'payment' => 'Bitte bezahl die Runde. Danach beginnt die Umsetzung sofort.',
            'paid' => 'Zahlung erhalten. Die Änderung wird jetzt umgesetzt.',
            'started' => 'Wird umgesetzt. Das dauert meist einige Minuten.',
            'result' => 'Fertig. Die neue Vorschau ist bereit.',
            'declined' => 'Diese Änderung passt nicht in eine Änderungsrunde. Die Runde wurde nicht umgesetzt.',
            'failed' => 'Das hat technisch nicht geklappt. Unser Team ist informiert und meldet sich.',
        ];
        $en = [
            'limit' => 'The limit for today is reached. Your message is saved and our team will get back to you.',
            'payment' => 'Please pay for the round. Work starts right after.',
            'paid' => 'Payment received. The change is being made now.',
            'started' => 'Working on it. This usually takes a few minutes.',
            'result' => 'Done. The new preview is ready.',
            'declined' => 'This change does not fit a change round. Nothing was changed.',
            'failed' => 'That did not work on our side. Our team knows and will get back to you.',
        ];

        return ($customer?->locale === 'en' ? $en : $de)[$type];
    }
}
