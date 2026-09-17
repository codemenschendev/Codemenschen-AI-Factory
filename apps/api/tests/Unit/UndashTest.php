<?php

namespace Tests\Unit;

use App\Services\ChangeChat;
use PHPUnit\Framework\TestCase;

/** Customer-facing text from the revise agent (summary, notes, declined) must not use dashes as breaks. */
class UndashTest extends TestCase
{
    public function test_spaced_dashes_become_commas(): void
    {
        $this->assertSame('Der Button ist jetzt blau, wie besprochen.', ChangeChat::undash('Der Button ist jetzt blau — wie besprochen.'));
        $this->assertSame('Der Button ist jetzt blau, wie besprochen.', ChangeChat::undash('Der Button ist jetzt blau – wie besprochen.'));
        $this->assertSame('blau, rot', ChangeChat::undash('blau—rot'));
    }

    public function test_punctuation_is_not_doubled(): void
    {
        $this->assertSame('Fertig. Danach kommt das Logo.', ChangeChat::undash('Fertig. — Danach kommt das Logo.'));
        $this->assertSame('Farbe, Schrift', ChangeChat::undash('Farbe, — Schrift'));
    }

    public function test_leading_and_trailing_dashes_are_dropped(): void
    {
        $this->assertSame('Logo getauscht', ChangeChat::undash('— Logo getauscht —'));
    }

    public function test_hyphens_and_ranges_stay(): void
    {
        $this->assertSame('E-Mail-Adresse, geöffnet 9–17 Uhr', ChangeChat::undash('E-Mail-Adresse, geöffnet 9–17 Uhr'));
    }
}
