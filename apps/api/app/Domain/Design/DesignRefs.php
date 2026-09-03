<?php

namespace App\Domain\Design;

/**
 * Screenshots of designs worth aiming at.
 *
 * These are references, not assets. Nothing here is ever shipped, embedded or served to a visitor:
 * one is shown to the model while it writes, the way a designer keeps a page open on the second
 * monitor, and what comes out is built from our own components. That distinction is the whole
 * point, and it is written into the instruction that travels with the picture.
 *
 * Kept apart from the ad photo library on purpose. That one holds pictures that go into finished
 * work; this one holds pictures that must never leave the machine.
 *
 * catalog.tsv columns: id, kind, note, tags, file.
 */
class DesignRefs
{
    public function __construct(private readonly string $dir) {}

    public function enabled(): bool
    {
        return trim((string) @file_get_contents($this->dir.'/state')) !== 'off';
    }

    public function setEnabled(bool $on): void
    {
        $this->ensure();
        file_put_contents($this->dir.'/state', $on ? "on\n" : "off\n");
    }

    /**
     * One reference for this build, as a data URI ready to hang on a chat message.
     *
     * Picks among the references filed for this kind, preferring whichever shares the most words
     * with the brief, so a salon brief lands on something warm rather than on a fintech dashboard.
     * Ties and total misses fall back to a random pick: two prototypes for the same trade should
     * not come out looking like the same page.
     *
     * @return array{id:string,note:string,data:string}|null
     */
    public function pick(string $kind, string $brief): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $rows = array_values(array_filter($this->rows(), fn ($r) => $r['kind'] === $kind
            && is_file($this->dir.'/img/'.$r['file'])));
        if ($rows === []) {
            return null;
        }

        $want = $this->words($brief);
        $best = [];
        $bestScore = -1;
        foreach ($rows as $row) {
            $score = count(array_intersect($want, $this->words($row['tags'].' '.$row['note'])));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$row];
            } elseif ($score === $bestScore) {
                $best[] = $row;
            }
        }

        $row = $best[array_rand($best)];
        $bytes = @file_get_contents($this->dir.'/img/'.$row['file']);
        if ($bytes === false) {
            return null;
        }

        return [
            'id' => $row['id'],
            'note' => $row['note'],
            'data' => 'data:image/jpeg;base64,'.base64_encode($bytes),
        ];
    }

    /**
     * Files a screenshot. Keyed by content hash, so capturing the same page twice is free.
     *
     * @return string|null the id, or null if it could not be stored
     */
    public function add(string $sourceFile, string $kind, string $note, string $tags = ''): ?string
    {
        if (! is_file($sourceFile)) {
            return null;
        }

        try {
            $this->ensure();
            $id = substr(hash_file('sha256', $sourceFile), 0, 12);
            foreach ($this->rows() as $row) {
                if ($row['id'] === $id) {
                    return $id;
                }
            }
            if (! $this->toJpeg($sourceFile, $this->dir.'/img/'.$id.'.jpg')) {
                return null;
            }
            $this->append([$id, $kind, $this->clean($note), $this->clean($tags), $id.'.jpg']);

            return $id;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int,array{id:string,kind:string,note:string,tags:string,file:string,bytes:int}> */
    public function all(?string $kind = null): array
    {
        $out = [];
        foreach (array_reverse($this->rows()) as $row) {
            if ($kind !== null && $row['kind'] !== $kind) {
                continue;
            }
            $row['bytes'] = (int) @filesize($this->dir.'/img/'.$row['file']);
            $out[] = $row;
        }

        return $out;
    }

    public function remove(string $id): bool
    {
        $file = null;
        $keep = ["id\tkind\tnote\ttags\tfile"];
        $found = false;
        foreach ($this->rows() as $row) {
            if ($row['id'] === $id) {
                $file = $row['file'];
                $found = true;

                continue;
            }
            $keep[] = implode("\t", [$row['id'], $row['kind'], $row['note'], $row['tags'], $row['file']]);
        }
        if (! $found) {
            return false;
        }
        file_put_contents($this->dir.'/catalog.tsv', implode("\n", $keep)."\n", LOCK_EX);
        if ($file) {
            @unlink($this->dir.'/img/'.$file);
        }

        return true;
    }

    /** @return array{refs:int,by_kind:array<string,int>,bytes:int,enabled:bool} */
    public function stats(): array
    {
        $rows = $this->rows();
        $by = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $by[$row['kind']] = ($by[$row['kind']] ?? 0) + 1;
            $bytes += (int) @filesize($this->dir.'/img/'.$row['file']);
        }

        return ['refs' => count($rows), 'by_kind' => $by, 'bytes' => $bytes, 'enabled' => $this->enabled()];
    }

    // ---------------------------------------------------------------------------------------

    /** @return array<int,array{id:string,kind:string,note:string,tags:string,file:string}> */
    private function rows(): array
    {
        $lines = @file($this->dir.'/catalog.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $out = [];
        foreach ($lines as $i => $line) {
            if ($i === 0 && str_starts_with($line, 'id')) {
                continue;
            }
            $c = explode("\t", $line);
            if (count($c) < 5) {
                continue;
            }
            $out[] = ['id' => $c[0], 'kind' => $c[1], 'note' => $c[2], 'tags' => $c[3], 'file' => $c[4]];
        }

        return $out;
    }

    /** @return array<int,string> */
    private function words(string $text): array
    {
        $parts = preg_split('/[^a-z0-9äöüß]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($parts, fn ($w) => mb_strlen($w) > 3)));
    }

    private function clean(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    private function ensure(): void
    {
        @mkdir($this->dir.'/img', 0775, true);
        if (! is_file($this->dir.'/catalog.tsv')) {
            file_put_contents($this->dir.'/catalog.tsv', "id\tkind\tnote\ttags\tfile\n");
        }
    }

    /** @param array<int,string> $cells */
    private function append(array $cells): void
    {
        $fh = fopen($this->dir.'/catalog.tsv', 'a');
        if ($fh === false) {
            return;
        }
        if (flock($fh, LOCK_EX)) {
            fwrite($fh, implode("\t", $cells)."\n");
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    /** Screenshots are large PNGs; the model reads a JPEG just as well and the prompt stays small. */
    private function toJpeg(string $src, string $dest): bool
    {
        foreach (['/usr/bin/convert', '/usr/bin/magick'] as $bin) {
            if (is_executable($bin)) {
                exec(escapeshellcmd($bin).' '.escapeshellarg($src)
                    .' -resize 1200x2400\> -quality 82 -strip '.escapeshellarg($dest).' 2>/dev/null', $_, $code);
                if ($code === 0 && is_file($dest)) {
                    return true;
                }
            }
        }

        return copy($src, $dest);
    }
}
