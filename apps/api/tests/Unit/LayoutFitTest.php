<?php

namespace Tests\Unit;

use App\Domain\Design\Layouts;
use App\Domain\Qa\LayoutFit;
use App\Domain\Qa\PageAudit;
use Tests\TestCase;

/**
 * A page built on a pack keeps what the pack was chosen for, or goes to repair. The cases are
 * the ones the first real builds got wrong.
 */
class LayoutFitTest extends TestCase
{
    private function keeps(string $slug): array
    {
        $pack = collect((new Layouts(resource_path('layouts')))->packs())->firstWhere('slug', $slug);

        return $pack['keeps'];
    }

    public function test_the_shipped_skeletons_keep_everything_they_ask_for(): void
    {
        foreach (['bakery-warm', 'physio-calm'] as $slug) {
            $this->assertNotSame([], $this->keeps($slug)['devices'], $slug);
            $html = (string) file_get_contents(resource_path("layouts/{$slug}.html"));
            $this->assertSame([], LayoutFit::findings($html, $this->keeps($slug), $slug), $slug);
        }
    }

    public function test_a_booking_form_further_down_is_not_a_form_in_the_first_screen(): void
    {
        $html = '<style>.x{}</style><section class="hero"><h1>Schmerzfrei</h1><a href="#b">Termin</a></section>'
            .'<section id="b"><form><select><option>A</option></select><button>Senden</button></form></section>';

        $found = LayoutFit::findings($html, ['sections' => 2, 'devices' => ['form-first']], 'physio-calm');

        $this->assertSame('layout-dropped', $found[0]['check']);
        $this->assertCount(1, $found[0]['elements']);
        $this->assertStringContainsString('first section must hold the working form', $found[0]['elements'][0]);
    }

    public function test_missing_sections_and_devices_are_each_named(): void
    {
        $html = '<style>.badge{transform:rotate(0deg)}</style><section>a</section><section><ol><li>1</li><li>2</li></ol></section>';

        $found = LayoutFit::findings($html, ['sections' => 5, 'devices' => ['rotated-badge', 'dotted-leader', 'quote', 'numbered-list']], 'bakery-warm');

        $this->assertCount(5, $found[0]['elements']);
        $this->assertStringContainsString('has 5 sections and the page has 2', $found[0]['elements'][0]);
    }

    public function test_a_page_that_kept_it_all_passes(): void
    {
        $html = '<style>.b{transform:rotate(-6deg)} li i{border-bottom:1px dotted #999}</style>'
            .'<section><form><input name="d"><button>Los</button></form></section><section><blockquote>Gut</blockquote>'
            .'<ol><li>a</li><li>b</li><li>c</li></ol></section>';

        $this->assertSame([], LayoutFit::findings($html, ['sections' => 2, 'devices' => ['form-first', 'rotated-badge', 'dotted-leader', 'quote', 'numbered-list']], 'x'));
    }

    public function test_putting_back_two_of_three_parts_counts_as_progress(): void
    {
        $before = [['severity' => 'blocking', 'check' => 'layout-dropped', 'elements' => ['a', 'b', 'c']]];
        $after = [['severity' => 'blocking', 'check' => 'layout-dropped', 'elements' => ['c']]];

        $this->assertLessThan(PageAudit::weight($before), PageAudit::weight($after));
        $this->assertSame(['layout-dropped'], array_column(PageAudit::repairable(['findings' => $before]), 'check'));
    }
}
