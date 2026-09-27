<?php

namespace App\Domain\Compliance\Services;

use Illuminate\Support\Str;

/**
 * Decides whether two names may refer to the same person or company, for
 * conflict checks. Tuned for Philippine names:
 *  - particles written apart or together ("De la Cruz" = "Dela Cruz"),
 *  - "Ma." for Maria, "Sto."/"Sta." for Santo/Santa,
 *  - honorifics and "Jr."/"III" ignored, accents ignored (Ñ = N),
 *  - corporate suffixes ignored ("Acme Corp." = "ACME Corporation"),
 *  - any word order ("DELA CRUZ, Juan"), and small misspellings.
 *
 * A match is reported with a score and the reason, strongest first.
 */
class NameMatcher
{
    public const THRESHOLD = 60;

    private const PARTICLES = [
        '/\bde\s+la\b/' => 'dela', '/\bde\s+las\b/' => 'delas', '/\bde\s+los\b/' => 'delos',
        '/\bdel\s+(?=\w)/' => 'del', '/\bsta\b/' => 'santa', '/\bsto\b/' => 'santo', '/\bma\b/' => 'maria',
        '/\bsan\s+(?=\w)/' => 'san',
    ];

    private const NOISE = [
        // Honorifics and generations.
        'atty', 'attorney', 'mr', 'mrs', 'ms', 'miss', 'dr', 'engr', 'hon', 'judge', 'sps', 'spouses', 'jr', 'sr', 'ii', 'iii', 'iv',
        // Company forms and filler words.
        'inc', 'incorporated', 'corp', 'corporation', 'co', 'company', 'ltd', 'limited', 'opc', 'llc', 'lp', 'plc', 'and', 'the', 'of',
    ];

    /** @return array{score: int, reason: string}|null  null when below the threshold */
    public function compare(string $query, string $candidate): ?array
    {
        $q = $this->tokens($query);
        $c = $this->tokens($candidate);
        if ($q === [] || $c === []) {
            return null;
        }

        if ($this->same($q, $c)) {
            return ['score' => 100, 'reason' => 'Same name'];
        }
        if (array_diff($q, $c) === []) {
            return ['score' => 90, 'reason' => count($q) === 1 ? 'Contains the name' : 'Contains every part of the name'];
        }

        $fuzzy = array_filter($q, fn (string $t) => $this->findSimilar($t, $c) !== null);
        if (count($fuzzy) === count($q) && count($q) >= 1 && max(array_map('mb_strlen', $q)) >= 4) {
            return ['score' => 75, 'reason' => 'Similar spelling'];
        }

        // Same surname and the same first initial: "J. Dela Cruz", "Jose Dela Cruz".
        if (count($q) >= 2) {
            $surname = $this->surname($query, $q);
            $given = array_values(array_diff($q, [$surname]));
            if ($surname !== null && mb_strlen($surname) >= 3 && $this->findSimilar($surname, $c) !== null
                && $given !== [] && collect($c)->contains(fn ($t) => $t !== $surname && $t[0] === $given[0][0])) {
                return ['score' => 60, 'reason' => 'Same surname and first initial'];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function tokens(string $name): array
    {
        $text = Str::lower(Str::ascii($name));
        $text = (string) preg_replace('/[^a-z0-9\s]+/', ' ', $text);
        $text = (string) preg_replace(array_keys(self::PARTICLES), array_values(self::PARTICLES), $text);

        return array_values(array_unique(array_filter(
            preg_split('/\s+/', trim($text)) ?: [],
            fn (string $t) => $t !== '' && ! in_array($t, self::NOISE, true) && (mb_strlen($t) >= 2 || ctype_digit($t)),
        )));
    }

    /** @param  list<string>  $a @param  list<string>  $b */
    private function same(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /** @param  list<string>  $candidates */
    private function findSimilar(string $token, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if ($c === $token) {
                return $c;
            }
        }

        $allowed = match (true) {
            mb_strlen($token) >= 8 => 2,
            mb_strlen($token) >= 4 => 1,
            default => 0,
        };
        foreach ($candidates as $c) {
            if ($allowed > 0 && abs(strlen($c) - strlen($token)) <= $allowed && levenshtein($token, $c) <= $allowed) {
                return $c;
            }
            // Sounds alike: "Fernandes" / "Fernandez", "Jhon" / "John".
            if (strlen($token) >= 4 && strlen($c) >= 4 && $token[0] === $c[0] && metaphone($token) === metaphone($c)) {
                return $c;
            }
        }

        return null;
    }

    /** "DELA CRUZ, Juan" -> dela (the part before the comma); "Juan Dela Cruz" -> the last word. */
    private function surname(string $original, array $tokens): ?string
    {
        if (str_contains($original, ',')) {
            $before = $this->tokens(explode(',', $original, 2)[0]);

            return $before[0] ?? null;
        }

        return $tokens[count($tokens) - 1] ?? null;
    }
}
