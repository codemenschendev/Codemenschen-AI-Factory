<?php

use App\Domain\Ai\CodexPage;
use App\Models\Prototype;
use Illuminate\Database\Migrations\Migration;

/**
 * The prototypes Codex drew before 2026-09-26 were all titled "Prototyp". They get the name the
 * writer gives them now: the website's address when one was read, else the first sentence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Prototype::where('title', 'Prototyp')->each(function (Prototype $p): void {
            $url = $p->qa['product']['url'] ?? null;
            $title = CodexPage::title((string) $p->prompt, is_string($url) ? ['url' => $url] : null);
            if ($title !== 'Prototyp') {
                $p->forceFill(['title' => $title])->saveQuietly();
            }
        });
    }

    public function down(): void {}
};
