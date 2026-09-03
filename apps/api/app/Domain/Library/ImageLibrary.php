<?php

namespace App\Domain\Library;

/**
 * The photos we have already paid to generate, kept so the next ad can reuse one.
 *
 * The catalog is the same catalog.tsv that ops/library.sh reads, in the same directory on the
 * host. One file, two clients: the operator works the library from the shell, the render job and
 * the admin panel work it from here, and neither has a private copy that can drift from the other.
 *
 * Columns: id, caption, project, shared, file. The caption is the English photo brief the
 * copywriter wrote for the scene, so a search here is a search against the same kind of sentence
 * the caller is about to hand to the image model.
 */
class ImageLibrary
{
    /**
     * Words that appear in almost every photo brief. Left in the caption for the human reading it,
     * taken out before matching, because "warm natural light in the background" matches everything
     * and would make the library hand back a hair salon for a car workshop.
     */
    private const NOISE = [
        'a', 'an', 'the', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'with', 'for', 'from', 'by',
        'her', 'his', 'their', 'its', 'this', 'that', 'is', 'are', 'no', 'not',
        'photo', 'image', 'shot', 'view', 'scene', 'background', 'foreground', 'space', 'frame',
        'light', 'lighting', 'lit', 'mood', 'soft', 'warm', 'bright', 'natural', 'clean', 'calm',
        'modern', 'small', 'large', 'close', 'up', 'lower', 'third', 'left', 'right', 'behind',
    ];

    /** Below this share of the query's own words, a hit is a coincidence rather than a match. */
    private const THRESHOLD = 0.55;

    /** And a short query can clear any ratio by accident, so demand real overlap too. */
    private const MIN_WORDS = 3;

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
     * The best photo for this brief, or null to go and generate one.
     *
     * A photo belongs to the project it was made for. Another project may only borrow it once
     * somebody has marked it shared: two competing businesses in the same town must not end up
     * running the same hero image.
     *
     * @return array{id:string,caption:string,file:string,score:float}|null
     */
    public function find(string $caption, ?string $project = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $want = $this->words($caption);
        if (count($want) < self::MIN_WORDS) {
            return null;
        }

        $best = null;
        foreach ($this->rows() as $row) {
            if ($row['project'] !== $project && ! $row['shared']) {
                continue;
            }
            if (! is_file($this->dir.'/img/'.$row['file'])) {
                continue;
            }

            $hits = count(array_intersect($want, $this->words($row['caption'])));
            $score = $hits / count($want);
            if ($hits >= self::MIN_WORDS && $score >= self::THRESHOLD && (! $best || $score > $best['score'])) {
                $best = ['id' => $row['id'], 'caption' => $row['caption'], 'file' => $row['file'], 'score' => $score];
            }
        }

        return $best;
    }

    /** Absolute path of a catalog entry's file, or null. */
    public function path(string $id): ?string
    {
        foreach ($this->rows() as $row) {
            if ($row['id'] === $id) {
                $p = $this->dir.'/img/'.$row['file'];

                return is_file($p) ? $p : null;
            }
        }

        return null;
    }

    /**
     * Files a freshly generated image so the next ad can find it. Keyed by the content hash, the
     * same key ops/library.sh uses, so the two never file the same picture twice.
     *
     * Never throws: a library that cannot be written is a missed saving, not a failed render.
     */
    public function remember(string $sourceFile, string $caption, ?string $project = null): ?string
    {
        $caption = trim(preg_replace('/\s+/', ' ', $caption) ?? '');
        if ($caption === '' || ! is_file($sourceFile)) {
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

            $dest = $this->dir.'/img/'.$id.'.jpg';
            if (! $this->toJpeg($sourceFile, $dest)) {
                return null;
            }

            $this->append([$id, $caption, (string) ($project ?? 'appwerk'), '0', $id.'.jpg']);

            return $id;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The whole catalog, newest first, optionally narrowed by a search box.
     *
     * @return array<int,array{id:string,caption:string,project:string,shared:bool,file:string,bytes:int}>
     */
    public function all(?string $query = null): array
    {
        $rows = array_reverse($this->rows());
        $terms = array_filter(array_map('trim', explode(' ', mb_strtolower((string) $query))));

        $out = [];
        foreach ($rows as $row) {
            if ($terms !== []) {
                $hay = mb_strtolower($row['caption'].' '.$row['project']);
                foreach ($terms as $term) {
                    if (! str_contains($hay, $term)) {
                        continue 2;
                    }
                }
            }
            $row['bytes'] = (int) @filesize($this->dir.'/img/'.$row['file']);
            $out[] = $row;
        }

        return $out;
    }

    public function setShared(string $id, bool $shared): bool
    {
        return $this->rewrite($id, fn (array $row) => [$row[0], $row[1], $row[2], $shared ? '1' : '0', $row[4]]);
    }

    public function setCaption(string $id, string $caption): bool
    {
        $caption = trim(preg_replace('/\s+/', ' ', $caption) ?? '');

        return $caption !== '' && $this->rewrite($id, fn (array $row) => [$row[0], $caption, $row[2], $row[3], $row[4]]);
    }

    public function remove(string $id): bool
    {
        $file = null;
        foreach ($this->rows() as $row) {
            if ($row['id'] === $id) {
                $file = $row['file'];
            }
        }
        if (! $this->rewrite($id, fn () => null)) {
            return false;
        }
        if ($file) {
            @unlink($this->dir.'/img/'.$file);
        }

        return true;
    }

    /** @return array{images:int,shared:int,bytes:int,enabled:bool} */
    public function stats(): array
    {
        $rows = $this->rows();
        $bytes = 0;
        foreach ($rows as $row) {
            $bytes += (int) @filesize($this->dir.'/img/'.$row['file']);
        }

        return [
            'images' => count($rows),
            'shared' => count(array_filter($rows, fn ($r) => $r['shared'])),
            'bytes' => $bytes,
            'enabled' => $this->enabled(),
        ];
    }

    // ---------------------------------------------------------------------------------------

    /** @return array<int,array{id:string,caption:string,project:string,shared:bool,file:string}> */
    private function rows(): array
    {
        $lines = @file($this->dir.'/catalog.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $out = [];
        foreach ($lines as $i => $line) {
            if ($i === 0 && str_starts_with($line, 'id')) {
                continue;   // header
            }
            $c = explode("\t", $line);
            if (count($c) < 5) {
                continue;
            }
            $out[] = [
                'id' => $c[0], 'caption' => $c[1], 'project' => $c[2],
                'shared' => $c[3] === '1', 'file' => $c[4],
            ];
        }

        return $out;
    }

    /** @return array<int,string> */
    private function words(string $text): array
    {
        $text = mb_strtolower($text);
        $parts = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            fn ($w) => mb_strlen($w) > 2 && ! in_array($w, self::NOISE, true),
        )));
    }

    private function ensure(): void
    {
        @mkdir($this->dir.'/img', 0775, true);
        if (! is_file($this->dir.'/catalog.tsv')) {
            file_put_contents($this->dir.'/catalog.tsv', "id\tcaption\tproject\tshared\tfile\n");
        }
    }

    /** @param array<int,string> $cells */
    private function append(array $cells): void
    {
        $this->ensure();
        $fh = fopen($this->dir.'/catalog.tsv', 'a');
        if ($fh === false) {
            return;
        }
        // The render job and the operator's shell can both be appending; a short exclusive lock is
        // enough to keep two lines from being interleaved into one broken row.
        if (flock($fh, LOCK_EX)) {
            fwrite($fh, implode("\t", $cells)."\n");
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    /** @param callable(array<int,string>):?array<int,string> $edit */
    private function rewrite(string $id, callable $edit): bool
    {
        $path = $this->dir.'/catalog.tsv';
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        $found = false;
        $out = [];
        foreach ($lines as $i => $line) {
            $c = explode("\t", $line);
            if ($i === 0 || count($c) < 5 || $c[0] !== $id) {
                $out[] = $line;

                continue;
            }
            $found = true;
            $new = $edit($c);
            if ($new !== null) {
                $out[] = implode("\t", $new);
            }
        }
        if (! $found) {
            return false;
        }

        file_put_contents($path, implode("\n", $out)."\n", LOCK_EX);

        return true;
    }

    /** ImageMagick if the image ships it, otherwise keep the original bytes under the .jpg name. */
    private function toJpeg(string $src, string $dest): bool
    {
        foreach (['/usr/bin/convert', '/usr/bin/magick'] as $bin) {
            if (is_executable($bin)) {
                exec(escapeshellcmd($bin).' '.escapeshellarg($src).' -quality 88 -strip '.escapeshellarg($dest).' 2>/dev/null', $_, $code);
                if ($code === 0 && is_file($dest)) {
                    return true;
                }
            }
        }

        return copy($src, $dest);
    }
}
