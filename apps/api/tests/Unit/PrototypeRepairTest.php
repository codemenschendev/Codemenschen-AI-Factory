<?php

namespace Tests\Unit;

use App\Domain\Ai\PrototypeWriter;
use App\Domain\Qa\PageAudit;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The gate between the model and the visitor.
 *
 * A real browser is not needed to test the gate, only a verdict, so the auditor is stubbed with
 * the reports it would return. What is being tested is the decision: when to spend a second
 * generation, and when to throw one away.
 */
class PrototypeRepairTest extends TestCase
{
    private function page(string $body): string
    {
        return '<!doctype html><html><head><title>X</title><link rel="stylesheet" href="house.css">'
            ."</head><body>{$body}</body></html>";
    }

    private function answers(string ...$bodies): void
    {
        config(['services.ai_image.base_url' => 'http://sidecar.test', 'services.ai_image.token' => 't']);
        $sequence = Http::sequence();
        foreach ($bodies as $body) {
            $sequence->pushResponse(Http::response(
                ['choices' => [['message' => ['content' => $this->page($body)]]]]
            ));
        }
        // whenEmpty, not an exception: one test deliberately runs the sequence dry to see what
        // the writer does when the repair call comes back with nothing.
        Http::fake(['*/v1/chat/completions' => $sequence->whenEmpty(Http::response(null, 502))]);
    }

    /** @param array<int,array<string,mixed>> $reports one per run() call, last one repeats */
    private function auditor(array $reports): PageAudit
    {
        return new class('/dev/null', null, $reports) extends PageAudit
        {
            public int $runs = 0;

            public function __construct(string $s, ?string $n, private array $reports)
            {
                parent::__construct($s, $n);
            }

            public function run(string $html): array
            {
                $i = min($this->runs++, count($this->reports) - 1);

                return $this->reports[$i];
            }
        };
    }

    private function fault(string $check = 'placeholder'): array
    {
        return ['ok' => false, 'findings' => [
            ['severity' => 'blocking', 'check' => $check, 'detail' => 'Item 1',
                'elements' => ['div.hero > p'], 'viewports' => ['320x640']],
        ]];
    }

    private function clean(): array
    {
        return ['ok' => true, 'findings' => []];
    }

    public function test_a_clean_page_costs_one_generation(): void
    {
        $this->answers('<h1>Salon</h1>');
        $audit = $this->auditor([$this->clean()]);

        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'site', null, $audit);

        $this->assertTrue($out['qa']['ok']);
        Http::assertSentCount(1);
    }

    public function test_a_fault_buys_exactly_one_repair(): void
    {
        $this->answers('<h1>Zu breit</h1>', '<h1>Passt</h1>');
        $audit = $this->auditor([$this->fault(), $this->clean()]);

        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'site', null, $audit);

        Http::assertSentCount(2);
        $this->assertTrue($out['qa']['ok']);
        $this->assertTrue($out['qa']['repaired']);
        $this->assertStringContainsString('Passt', $out['html']);

        // The second call carries the browser's complaint, in words the model can act on.
        Http::assertSent(function ($request) {
            $messages = $request['messages'];
            $last = $messages[count($messages) - 1]['content'];

            return is_string($last) && str_contains($last, 'placeholder')
                && str_contains($last, 'div.hero > p') && str_contains($last, '320x640');
        });
    }

    public function test_a_repair_that_did_not_help_is_thrown_away(): void
    {
        // Two faults in, two faults out: the repair bought nothing, so the first page stands.
        // Shipping the second would mean shipping an unreviewed page to fix a reviewed one.
        $this->answers('<h1>Original</h1>', '<h1>Schlimmer</h1>');
        $audit = $this->auditor([$this->fault(), $this->fault('broken-image')]);

        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'site', null, $audit);

        Http::assertSentCount(2);
        $this->assertStringContainsString('Original', $out['html']);
        $this->assertFalse($out['qa']['ok']);
        $this->assertFalse($out['qa']['repaired']);
    }

    public function test_a_repair_that_never_arrived_says_why(): void
    {
        // One answer only, so the repair call runs out of fakes and comes back empty.
        $this->answers('<h1>Original</h1>');
        $audit = $this->auditor([$this->fault()]);

        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'site', null, $audit);

        $this->assertFalse($out['qa']['repaired']);
        $this->assertSame('http 502', $out['qa']['repair_failed']);
        $this->assertStringContainsString('Original', $out['html']);
    }

    public function test_a_fault_the_model_does_not_own_costs_no_generation(): void
    {
        // Overflow is nearly always the stylesheet's, and the model is told not to write CSS.
        // It is still reported and the page is still marked as shipped with a fault.
        $this->answers('<h1>Zu breit</h1>');
        $audit = $this->auditor([$this->fault('overflow')]);

        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'site', null, $audit);

        Http::assertSentCount(1);
        $this->assertFalse($out['qa']['ok']);
        $this->assertArrayNotHasKey('repaired', $out['qa']);
    }

    public function test_without_an_auditor_nothing_changes(): void
    {
        $this->answers('<h1>Salon</h1>');

        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'site');

        Http::assertSentCount(1);
        $this->assertNull($out['qa']['ok']);
        $this->assertStringContainsString('Salon', $out['html']);
    }
}
