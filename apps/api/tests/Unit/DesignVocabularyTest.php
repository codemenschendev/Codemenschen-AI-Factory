<?php

namespace Tests\Unit;

use App\Domain\Design\DesignLibrary;
use Tests\TestCase;

/** The PHP copies of the labeller's vocabularies must not drift from the script that owns them. */
class DesignVocabularyTest extends TestCase
{
    /** @return list<string> */
    private function pythonList(string $name, string $script = 'label-design-library.py'): array
    {
        $src = (string) file_get_contents(base_path('tools/'.$script));
        $this->assertSame(1, preg_match('/^'.$name.' = \[(.*?)\]/ms', $src, $m), "$name in the script");
        preg_match_all("/'([a-z_]+)'/", $m[1], $words);

        return $words[1];
    }

    public function test_industries_match_the_labeller(): void
    {
        $this->assertSame($this->pythonList('INDUSTRIES'), DesignLibrary::INDUSTRIES);
    }

    public function test_web_industries_match_the_web_labeller(): void
    {
        // A page or an ad may be for a trade the app labeller never meets: an agency, a joinery.
        $this->assertSame($this->pythonList('INDUSTRIES', 'label-web-library.py'), DesignLibrary::WEB_INDUSTRIES);
    }

    public function test_screen_types_match_the_labeller(): void
    {
        $this->assertSame($this->pythonList('SCREEN_TYPES'), DesignLibrary::SCREEN_TYPES);
    }
}
