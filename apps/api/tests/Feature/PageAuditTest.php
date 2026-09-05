<?php

namespace Tests\Feature;

use App\Domain\Qa\PageAudit;
use Tests\TestCase;

/**
 * The auditor itself, driving a real browser over a real page.
 *
 * Skipped where node or chromium is not installed, which is every developer machine that has not
 * asked for them and no CI box we run. That is deliberate: the checks are only meaningful against
 * a browser, and a mocked browser would test the mock.
 */
class PageAuditTest extends TestCase
{
    private function audit(): PageAudit
    {
        $a = app(PageAudit::class);
        if (! $a->available()) {
            $this->markTestSkipped('no node or no qa-page.cjs on this machine');
        }

        return $a;
    }

    public function test_a_clean_page_passes(): void
    {
        $report = $this->audit()->run(<<<'HTML'
            <!doctype html><meta charset="utf-8"><title>Sauber</title>
            <style>body{margin:0;font:16px sans-serif;background:#fff;color:#111}
            .wrap{max-width:100%;padding:20px;box-sizing:border-box}
            a.btn{display:inline-block;padding:12px 20px;background:#0a5c2b;color:#fff}</style>
            <div class="wrap"><h1>Haarstudio Lena</h1><p>Termin in zwei Minuten gebucht.</p>
            <a class="btn" href="#">Termin buchen</a></div>
            HTML);

        $this->assertTrue($report['ok'], json_encode($report['findings']));
        $this->assertSame([], PageAudit::blocking($report));
    }

    public function test_a_page_wider_than_the_phone_is_blocking(): void
    {
        $report = $this->audit()->run(
            '<!doctype html><meta charset="utf-8"><title>Breit</title>'
            .'<style>body{margin:0}.w{width:900px;background:#eee}</style><div class="w">Breit</div>'
        );

        $this->assertFalse($report['ok']);
        $checks = array_column(PageAudit::blocking($report), 'check');
        $this->assertContains('overflow', $checks);

        // The finding names the element that sticks out, not just the fact that something does.
        $overflow = collect($report['findings'])->firstWhere('check', 'overflow');
        $this->assertNotEmpty($overflow['elements']);
        $this->assertStringContainsString('div.w', $overflow['elements'][0]);
    }

    public function test_a_row_that_scrolls_sideways_must_hide_its_bar(): void
    {
        // The fault the user pointed at on a phone prototype: a grey bar across the screen. A row
        // of cards may scroll, and the half-cut card at the edge is what says so.
        $row = '<div class="row %s"><div>Eins</div><div>Zwei</div><div>Drei</div></div>';
        $css = '<style>body{margin:0}.row{display:flex;gap:8px;overflow-x:auto}'
            .'.row>div{flex:0 0 200px;height:80px;background:#eee}'
            .'.quiet{scrollbar-width:none}.hidden::-webkit-scrollbar{display:none}</style>';

        $loud = $this->audit()->run('<!doctype html><meta charset="utf-8"><title>Reihe</title>'
            .$css.sprintf($row, 'loud'));
        $checks = array_column(PageAudit::blocking($loud), 'check');
        $this->assertContains('sideways-scrollbar', $checks);

        // Both ways of hiding it count, because the model may reach for either.
        foreach (['quiet', 'hidden'] as $way) {
            $ok = $this->audit()->run('<!doctype html><meta charset="utf-8"><title>Reihe</title>'
                .$css.sprintf($row, $way));
            $this->assertNotContains('sideways-scrollbar', array_column($ok['findings'], 'check'), $way);
        }
    }

    public function test_a_dash_as_a_sentence_break_is_blocking_and_names_the_line(): void
    {
        // "codemenschen.at — Weihnachtskampagne" went out as the title of an ad prototype after
        // the prompt had said no dash twice. The words are the model's, so the audit sends them
        // back; a hyphen inside a word and a minus in a price are not what this is about.
        $report = $this->audit()->run('<!doctype html><meta charset="utf-8">'
            .'<title>Bäckerei Rupert – Frisch seit 1923</title>'
            .'<h1>Brot — jeden Morgen frisch</h1><p>Kipferl 1,20 Euro. Öffnungszeiten: 6-18 Uhr, Mo-Sa.</p>');

        $dash = collect(PageAudit::blocking($report))->firstWhere('check', 'dash');
        $this->assertNotNull($dash, json_encode($report['findings']));
        $this->assertSame(2, (int) $dash['detail']);
        $this->assertStringContainsString('title:', $dash['elements'][0]);
        $this->assertStringContainsString('h1', $dash['elements'][1]);
        $this->assertContains('dash', array_column(PageAudit::repairable($report), 'check'));

        $ok = $this->audit()->run('<!doctype html><meta charset="utf-8"><title>Bäckerei Rupert</title>'
            .'<p>Öffnungszeiten: 6-18 Uhr, Mo-Sa. Kipferl 1,20 Euro.</p>');
        $this->assertNotContains('dash', array_column($ok['findings'], 'check'));
    }

    public function test_an_app_page_keeps_the_top_band_free_for_the_island(): void
    {
        // "CHÀO BUỔI SÁNG" under a black pill, twice in one day: the frame paints the status bar
        // and the Dynamic Island over the page's top 54px, and the model drew there.
        $app = fn (string $inset) => '<!doctype html><meta charset="utf-8"><title>App</title>'
            .'<style>body{margin:0}.app{padding-top:'.$inset.'}.bar{padding:12px;background:#eee}</style>'
            .'<body class="app-page"><div class="app"><input class="bar" placeholder="Bạn muốn đi đâu?"></div></body>';

        $under = $this->audit()->run($app('0'));
        $this->assertContains('under-the-island', array_column(PageAudit::blocking($under), 'check'));
        $this->assertContains('under-the-island', array_column(PageAudit::repairable($under, ownsStyle: true), 'check'));

        $ok = $this->audit()->run($app('54px'));
        $this->assertNotContains('under-the-island', array_column($ok['findings'], 'check'));

        // A website has no island: the same bar at the top of a plain page is fine.
        $site = $this->audit()->run(str_replace('class="app-page"', '', $app('0')));
        $this->assertNotContains('under-the-island', array_column($site['findings'], 'check'));
    }

    public function test_text_the_model_did_not_write_is_blocking(): void
    {
        $report = $this->audit()->run(
            '<!doctype html><meta charset="utf-8"><title>Platzhalter</title><p>Lorem ipsum dolor sit amet</p>'
        );

        $this->assertFalse($report['ok']);
        $this->assertContains('placeholder', array_column(PageAudit::blocking($report), 'check'));
    }

    public function test_a_judgement_call_does_not_block(): void
    {
        // Grey on white is a real finding and a wrong one often enough that it must not cost a
        // generation: measured, reported, not blocking.
        $report = $this->audit()->run(
            '<!doctype html><meta charset="utf-8"><title>Blass</title>'
            .'<style>body{background:#fff}p{color:#ccc}</style><p>Kaum zu lesen</p>'
        );

        $this->assertTrue($report['ok']);
        $this->assertContains('contrast', array_column($report['findings'], 'check'));
    }

    public function test_one_finding_per_fault_not_one_per_width(): void
    {
        $report = $this->audit()->run(
            '<!doctype html><meta charset="utf-8"><title>Einmal</title><p>Lorem ipsum dolor</p>'
        );

        $placeholders = array_filter($report['findings'], fn ($f) => $f['check'] === 'placeholder');
        $this->assertCount(1, $placeholders);
        $this->assertCount(3, reset($placeholders)['viewports']);
    }

    public function test_a_missing_auditor_is_a_skip_not_a_failure(): void
    {
        $report = (new PageAudit('/nowhere/qa-page.cjs'))->run('<!doctype html><p>hi</p>');

        $this->assertNull($report['ok']);
        $this->assertSame([], $report['findings']);
        $this->assertArrayHasKey('skipped', $report);
    }
}
