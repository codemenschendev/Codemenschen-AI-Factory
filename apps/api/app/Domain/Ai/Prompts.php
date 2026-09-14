<?php

namespace App\Domain\Ai;

use RuntimeException;

/**
 * The words the AI is given, read from resources/prompts.
 *
 * They live in plain text files so the owner can read and change them on GitHub without finding
 * them inside PHP. The code still decides which file goes where and fills in the {placeholders};
 * a file only holds wording. A missing file is a broken deploy, so it fails loudly instead of
 * sending the model an empty instruction.
 */
final class Prompts
{
    /** @param  array<string,string>  $vars  replaces {name} with the value, once, never recursively */
    public static function get(string $name, array $vars = []): string
    {
        $path = resource_path('prompts/'.$name.'.md');
        if (! is_file($path)) {
            throw new RuntimeException("Prompt file missing: resources/prompts/{$name}.md");
        }

        $text = rtrim((string) file_get_contents($path));
        if ($vars === []) {
            return $text;
        }

        $map = [];
        foreach ($vars as $key => $value) {
            $map['{'.$key.'}'] = $value;
        }

        // strtr, not str_replace: a customer's brief that happens to contain "{stats}" is
        // inserted as it is and never expanded a second time.
        return strtr($text, $map);
    }
}
