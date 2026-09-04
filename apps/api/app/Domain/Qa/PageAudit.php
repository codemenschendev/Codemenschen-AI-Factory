<?php

namespace App\Domain\Qa;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Opens a generated page in a real browser at three widths and reports what is wrong with it.
 *
 * Until this existed, nobody looked at what the model wrote before a visitor did. The three
 * horizontal-overflow bugs we found by hand on our own landing page were found by measuring, not
 * by reading, and a generated page gets no reading either.
 *
 * The audit can never fail a build. A page with an unnoticed flaw still beats no page, so every
 * failure here returns a report that says it was skipped and why, and the caller carries on.
 */
class PageAudit
{
    /** Wall clock for three viewports in one browser. Beyond this something is wrong, not slow. */
    private const TIMEOUT = 90;

    public function __construct(private readonly string $script, private readonly ?string $node = null) {}

    public function available(): bool
    {
        return is_file($this->script) && $this->binary() !== null;
    }

    /**
     * @return array{ok:bool|null,findings:array<int,array<string,mixed>>,viewports?:array<string,int>,skipped?:string}
     */
    public function run(string $html): array
    {
        $node = $this->binary();
        if ($node === null || ! is_file($this->script)) {
            return ['ok' => null, 'findings' => [], 'skipped' => 'no node or no audit script'];
        }

        // A real file, not a data: URL: file:// is the only scheme where a relative asset in the
        // page resolves the way it will once the page is served.
        $file = tempnam(sys_get_temp_dir(), 'qa-').'.html';
        file_put_contents($file, $html);

        try {
            $proc = new Process([$node, $this->script, $file], null, null, null, self::TIMEOUT);
            $proc->run();
            $report = json_decode(trim($proc->getOutput()), true);

            if (! is_array($report) || ! array_key_exists('ok', $report)) {
                Log::warning('page audit returned nothing usable', [
                    'exit' => $proc->getExitCode(),
                    'stderr' => mb_substr($proc->getErrorOutput(), 0, 300),
                ]);

                return ['ok' => null, 'findings' => [], 'skipped' => 'auditor returned nothing usable'];
            }

            $report['findings'] = array_values($report['findings'] ?? []);

            return $report;
        } catch (\Throwable $e) {
            return ['ok' => null, 'findings' => [], 'skipped' => mb_substr($e->getMessage(), 0, 160)];
        } finally {
            @unlink($file);
        }
    }

    /**
     * Faults the model can fix by rewriting its own markup.
     *
     * Overflow is not one of them. Both of the first two audited prototypes scrolled sideways and
     * both times the cause was in house.css, which the model is told not to touch and could not
     * fix if it tried. Sending those to a second generation costs three minutes of the visitor's
     * wait and changes nothing. They stay in the report and in the admin list, which is how those
     * stylesheet bugs got found in the first place.
     */
    private const MODEL_OWNED = ['placeholder', 'broken-image', 'script-error', 'console-error'];

    /** Everything a browser would call broken. @return array<int,array<string,mixed>> */
    public static function blocking(array $report): array
    {
        return array_values(array_filter(
            $report['findings'] ?? [],
            fn (array $f) => ($f['severity'] ?? '') === 'blocking',
        ));
    }

    /** The subset worth spending a generation on. @return array<int,array<string,mixed>> */
    public static function repairable(array $report): array
    {
        return array_values(array_filter(
            self::blocking($report),
            fn (array $f) => in_array($f['check'] ?? '', self::MODEL_OWNED, true),
        ));
    }

    /**
     * The findings as a brief the model can act on.
     *
     * Deliberately terse and free of prose: it is prepended to a repair request, and every word
     * that is not a fault is a word that dilutes the instruction.
     */
    public static function brief(array $findings): string
    {
        $lines = [];
        foreach (array_slice($findings, 0, 12) as $f) {
            $where = implode(', ', $f['viewports'] ?? []);
            $what = trim(($f['check'] ?? '?').': '.($f['detail'] ?? ''));
            $on = $f['elements'] ?? [];
            $lines[] = '- '.$what.($where !== '' ? " (at {$where})" : '')
                .($on ? "\n    ".implode("\n    ", array_slice($on, 0, 4)) : '');
        }

        return implode("\n", $lines);
    }

    private function binary(): ?string
    {
        foreach (array_filter([$this->node, '/usr/bin/node', '/usr/local/bin/node', '/opt/homebrew/bin/node']) as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }

        return null;
    }
}
