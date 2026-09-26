<?php

namespace Tests\Feature;

use App\Domain\Ai\CodexPage;
use Tests\TestCase;

/** A prototype drawn as a picture is named after the business, not "Prototyp" (2026-09-26). */
class MockupTitleTest extends TestCase
{
    public function test_the_website_name_comes_first_without_its_tagline(): void
    {
        $this->assertSame('Küstenpatent Kroatien', CodexPage::title('x', ['url' => 'https://www.kuestenpatent-kroatien.at/', 'name' => 'Küstenpatent Kroatien | Boat Skipper B in einem Tag']));
    }

    public function test_then_the_address_then_the_first_sentence(): void
    {
        $this->assertSame('kuestenpatent-kroatien.at', CodexPage::title('x', ['url' => 'https://www.kuestenpatent-kroatien.at/']));
        $this->assertSame('Eine Website für ein Friseurstudio in Wien', CodexPage::title("Eine Website für ein Friseurstudio in Wien. Mit Preisliste.", null));
        $this->assertSame('Prototyp', CodexPage::title('  ', null));
    }

    public function test_a_long_sentence_is_cut(): void
    {
        $title = CodexPage::title(str_repeat('Bäckerei Lang ', 12), null);
        $this->assertLessThanOrEqual(70, mb_strlen($title));
        $this->assertStringEndsWith('…', $title);
    }
}
