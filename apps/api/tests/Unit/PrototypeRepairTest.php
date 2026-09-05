<?php

namespace Tests\Unit;

use App\Domain\Ai\PrototypePhoto;
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

    public function test_overflow_is_the_model_s_fault_when_the_model_wrote_the_css(): void
    {
        // A free prototype styles itself, so a page that scrolls sideways is its own mistake and
        // it can be asked to fix it.
        $this->answers('<h1>Zu breit</h1>', '<h1>Passt</h1>');
        $audit = $this->auditor([$this->fault('overflow'), $this->clean()]);

        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'site', null, $audit);

        Http::assertSentCount(2);
        $this->assertTrue($out['qa']['repaired']);
    }

    public function test_the_ad_prototype_repairs_its_own_overflow_now(): void
    {
        // It was the last page drawn on house.css, where an overflow was ours and a repair call
        // could change nothing. It writes its own stylesheet now, so the fault is its own.
        $this->answers('<h1>Zu breit</h1>', '<h1>Passt</h1>');
        $audit = $this->auditor([$this->fault('overflow'), $this->clean()]);

        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'ads', null, $audit);

        Http::assertSentCount(2);
        $this->assertTrue($out['qa']['repaired']);
    }

    public function test_the_page_is_audited_again_once_the_photographs_are_in(): void
    {
        // The verdict has to describe the page that ships. A dentist app came back clean and then
        // reached 273px past a 390px screen once five photographs were in it.
        $this->answers('<h1>Salon</h1><div class="photo-wide">Lena am Waschbecken</div>');
        $audit = $this->auditor([$this->clean(), $this->fault('overflow')]);

        $photo = new class extends PrototypePhoto
        {
            public function __construct() {}

            public function apply(string $html): array
            {
                return ['html' => $html.'<!--photo-->', 'photo' => 'Lena am Waschbecken',
                    'photos' => ['Lena am Waschbecken'], 'source' => 'stock', 'sources' => ['stock'],
                    'credit' => 'Anna', 'credit_url' => 'u', 'credits' => ['Anna']];
            }
        };

        $out = app(PrototypeWriter::class)->build('Ein Salon in Wien', 'app', null, $audit, null, $photo);

        $this->assertFalse($out['qa']['ok'], 'the second audit is the one that counts');
        $this->assertSame('stock', $out['qa']['photo_source'], 'and the photo facts survive it');
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
