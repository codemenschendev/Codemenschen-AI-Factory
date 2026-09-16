<?php

namespace Tests\Unit;

use App\Domain\Qa\ContentClaims;
use App\Domain\Qa\PageAudit;
use PHPUnit\Framework\TestCase;

/**
 * The two faults the first pack builds shipped: bread prices nobody marked as examples, and a
 * list of health insurers the customer never named.
 */
class ContentClaimsTest extends TestCase
{
    private function checks(string $html, string $prompt, string $kind = 'site'): array
    {
        return array_column(ContentClaims::findings($html, $prompt, $kind), 'check');
    }

    public function test_prices_in_adjacent_tags_without_an_example_line_are_caught(): void
    {
        $html = '<html><body><li><small>900 g, saftig</small><b>4,80&nbsp;€</b></li><li>Melange € 3,30</li></body></html>';

        $found = ContentClaims::findings($html, 'Eine Bäckerei in Gössendorf', 'site');
        $this->assertSame(['price-unmarked'], array_column($found, 'check'));
        $this->assertSame(["4,80\u{00A0}€", '€ 3,30'], $found[0]['elements']);
    }

    public function test_marked_prices_given_prices_and_non_site_kinds_pass(): void
    {
        $html = '<body><p>Beispielpreise</p><b>4,80 €</b></body>';
        $this->assertSame([], $this->checks($html, 'Eine Bäckerei'));

        $bare = '<body><b>4,80 €</b></body>';
        $this->assertSame([], $this->checks($bare, 'Eine Bäckerei, Roggenbrot 4,80 €'));
        $this->assertSame([], $this->checks($bare, 'Eine Bäckerei', 'app'));
        $this->assertSame([], $this->checks('<body>1.000 g Roggen, 50 Minuten</body>', 'Eine Bäckerei'));
    }

    public function test_insurers_and_years_the_customer_never_gave_are_caught(): void
    {
        $html = '<body><p>Zuschuss möglich bei</p><span>ÖGK</span><span>SVS</span><p>Seit 2011 in Linz</p><p>Über 30 Jahre im Team</p></body>';

        $found = ContentClaims::findings($html, 'Eine Physiotherapie Praxis in Linz', 'site');
        $this->assertSame(['claim-invented'], array_column($found, 'check'));
        $this->assertSame(['ÖGK', 'SVS', 'Seit 2011', 'Über 30 Jahre im Team'], $found[0]['elements']);
    }

    public function test_what_the_customer_said_may_be_printed(): void
    {
        $html = '<body><p>Seit 1998 in Gössendorf</p><p>Meisterbetrieb</p></body>';

        $this->assertSame([], $this->checks($html, 'Meisterbetrieb seit 1998 in Gössendorf'));
    }

    public function test_both_faults_go_to_the_repair_pass(): void
    {
        $report = ['findings' => ContentClaims::findings('<body><b>5 €</b> ÖGK</body>', 'Physio', 'site')];

        $this->assertSame(['price-unmarked', 'claim-invented'], array_column(PageAudit::repairable($report), 'check'));
    }
}
