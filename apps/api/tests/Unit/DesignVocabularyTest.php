<?php

namespace Tests\Unit;

use App\Domain\Design\DesignLibrary;
use Tests\TestCase;

/** The PHP copies of the labeller's vocabularies must not drift from the script that owns them. */
class DesignVocabularyTest extends TestCase
{
    /** @return list<string> */
    private function pythonList(string $name): array
    {
        $src = (string) file_get_contents(base_path('tools/label-design-library.py'));
        $this->assertSame(1, preg_match('/^'.$name.' = \[(.*?)\]/ms', $src, $m), "$name in the script");
        preg_match_all("/'([a-z_]+)'/", $m[1], $words);

        return $words[1];
    }

    public function test_industries_match_the_labeller(): void
    {
        $this->assertSame($this->pythonList('INDUSTRIES'), DesignLibrary::INDUSTRIES);
    }

    public function test_screen_types_match_the_labeller(): void
    {
        $this->assertSame($this->pythonList('SCREEN_TYPES'), DesignLibrary::SCREEN_TYPES);
    }
}
