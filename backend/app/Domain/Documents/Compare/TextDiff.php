<?php

namespace App\Domain\Documents\Compare;

/**
 * A word-level redline between two texts, the way lawyers read one: whole
 * paragraphs are matched first, then the words inside paragraphs that
 * changed, so an edit in one clause does not smear across the document.
 *
 * Myers' O(ND) algorithm, on lines and then on words; the result is a list
 * of segments [type => equal|insert|delete, text => string] which, read in
 * order, spell the old text (equal + delete) and the new one (equal + insert).
 */
class TextDiff
{
    public const EQUAL = 'equal';

    public const INSERT = 'insert';

    public const DELETE = 'delete';

    /**
     * Edits beyond which a run is shown as replaced wholesale rather than
     * searched for the shortest edit (the search keeps O(D²) state).
     */
    private const MAX_EDITS = 1000;

    /** Tokens in a changed block above which it is not compared word by word. */
    private const MAX_BLOCK_TOKENS = 20000;

    /**
     * @return array{segments: list<array{type: string, text: string}>, inserted_words: int, deleted_words: int}
     */
    public function compare(string $old, string $new): array
    {
        $oldLines = $this->lines($old);
        $newLines = $this->lines($new);
        $segments = [];
        $deleted = [];
        $inserted = [];

        // Changed paragraphs come out of the line diff as a run of deletes and
        // inserts; those runs are compared again word by word.
        $flush = function () use (&$segments, &$deleted, &$inserted) {
            if ($deleted && $inserted && count($deleted) === count($inserted)) {
                // Paragraph for paragraph (the usual case: each one edited in place).
                foreach ($deleted as $i => $line) {
                    foreach ($this->diff($this->words($line), $this->words($inserted[$i])) as $token) {
                        $segments[] = $token;
                    }
                }
            } elseif ($deleted && $inserted && strlen(implode('', $deleted)) + strlen(implode('', $inserted)) < self::MAX_BLOCK_TOKENS * 4) {
                foreach ($this->diff($this->words(implode('', $deleted)), $this->words(implode('', $inserted))) as $token) {
                    $segments[] = $token;
                }
            } else {
                foreach ($deleted as $line) {
                    $segments[] = [self::DELETE, $line];
                }
                foreach ($inserted as $line) {
                    $segments[] = [self::INSERT, $line];
                }
            }
            $deleted = $inserted = [];
        };

        foreach ($this->diff($oldLines, $newLines) as [$type, $line]) {
            if ($type === self::EQUAL) {
                $flush();
                $segments[] = [self::EQUAL, $line];
            } elseif ($type === self::DELETE) {
                $deleted[] = $line;
            } else {
                $inserted[] = $line;
            }
        }
        $flush();

        return $this->merge($segments);
    }

    /** Lines, each keeping its newline, so the segments rebuild the text exactly. */
    private function lines(string $text): array
    {
        return preg_split('/(?<=\n)/u', str_replace("\r\n", "\n", $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Words and the whitespace between them, as separate tokens. */
    private function words(string $text): array
    {
        return preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Myers' shortest edit script between two token lists.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: string, 1: string}>
     */
    private function diff(array $a, array $b): array
    {
        // Common prefix and suffix need no search (most of a revised contract).
        $prefix = 0;
        while ($prefix < count($a) && $prefix < count($b) && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < count($a) - $prefix && $suffix < count($b) - $prefix && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
            $suffix++;
        }

        $head = array_map(fn ($t) => [self::EQUAL, $t], array_slice($a, 0, $prefix));
        $tail = array_map(fn ($t) => [self::EQUAL, $t], array_slice($a, count($a) - $suffix));
        $a = array_slice($a, $prefix, count($a) - $prefix - $suffix);
        $b = array_slice($b, $prefix, count($b) - $prefix - $suffix);

        return [...$head, ...$this->myers($a, $b), ...$tail];
    }

    private function myers(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0) {
            return [...array_map(fn ($t) => [self::DELETE, $t], $a), ...array_map(fn ($t) => [self::INSERT, $t], $b)];
        }

        $max = min($n + $m, self::MAX_EDITS);
        $v = [1 => 0];
        $trace = [];

        for ($d = 0; $d <= $max; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                $x = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1)))
                    ? ($v[$k + 1] ?? 0)
                    : ($v[$k - 1] ?? 0) + 1;
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return $this->backtrack($trace, $a, $b, $n, $m);
                }
            }
        }

        // Too different to search: show the run as replaced.
        return [...array_map(fn ($t) => [self::DELETE, $t], $a), ...array_map(fn ($t) => [self::INSERT, $t], $b)];
    }

    private function backtrack(array $trace, array $a, array $b, int $x, int $y): array
    {
        $ops = [];
        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            $prevK = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) ? $k + 1 : $k - 1;
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $ops[] = [self::EQUAL, $a[--$x]];
                $y--;
            }
            if ($d > 0) {
                $ops[] = $x === $prevX ? [self::INSERT, $b[--$y]] : [self::DELETE, $a[--$x]];
            }
        }

        return array_reverse($ops);
    }

    /** Join neighbouring tokens of the same kind, and count changed words. */
    private function merge(array $tokens): array
    {
        $segments = [];
        $inserted = $deleted = 0;

        foreach ($tokens as [$type, $text]) {
            $words = preg_match_all('/\S+/u', $text);
            if ($type === self::INSERT) {
                $inserted += $words;
            } elseif ($type === self::DELETE) {
                $deleted += $words;
            }

            $last = array_key_last($segments);
            // Whitespace between two changes of the same kind belongs to them.
            if ($last !== null && $segments[$last]['type'] === $type) {
                $segments[$last]['text'] .= $text;
            } else {
                $segments[] = ['type' => $type, 'text' => $text];
            }
        }

        return ['segments' => $this->absorbSpaces($segments), 'inserted_words' => $inserted, 'deleted_words' => $deleted];
    }

    /**
     * "delete · space · delete" reads better as one struck-through phrase:
     * fold a lone unchanged space between two edits into both sides.
     */
    private function absorbSpaces(array $segments): array
    {
        $out = [];
        foreach ($segments as $i => $segment) {
            $prev = $out[array_key_last($out) ?? -1] ?? null;
            $next = $segments[$i + 1] ?? null;
            if ($segment['type'] === self::EQUAL && $prev && $next && $prev['type'] !== self::EQUAL && $next['type'] !== self::EQUAL
                && preg_match('/^[ \t]+$/u', $segment['text'])) {
                // Keep it on both sides of the change: struck in the old text, underlined in the new.
                $out[] = ['type' => self::DELETE, 'text' => $segment['text']];
                $out[] = ['type' => self::INSERT, 'text' => $segment['text']];

                continue;
            }
            $out[] = $segment;
        }

        // Regroup so each change reads "old words" then "new words".
        $grouped = [];
        $run = [];
        $flush = function () use (&$grouped, &$run) {
            if (! $run) {
                return;
            }
            $del = implode('', array_map(fn ($s) => $s['text'], array_filter($run, fn ($s) => $s['type'] === self::DELETE)));
            $ins = implode('', array_map(fn ($s) => $s['text'], array_filter($run, fn ($s) => $s['type'] === self::INSERT)));
            if ($del !== '') {
                $grouped[] = ['type' => self::DELETE, 'text' => $del];
            }
            if ($ins !== '') {
                $grouped[] = ['type' => self::INSERT, 'text' => $ins];
            }
            $run = [];
        };
        foreach ($out as $segment) {
            if ($segment['type'] === self::EQUAL) {
                $flush();
                $grouped[] = $segment;
            } else {
                $run[] = $segment;
            }
        }
        $flush();

        return $grouped;
    }
}
