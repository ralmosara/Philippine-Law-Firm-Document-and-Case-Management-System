<?php

namespace App\Domain\Evidence;

use App\Domain\Evidence\Models\Exhibit;
use App\Domain\Matters\Models\Matter;
use Illuminate\Support\Collection;

/**
 * Philippine marking practice: the plaintiff, petitioner or prosecution marks
 * its exhibits with capital letters (A to Z, then AA, BB, ...), the defendant,
 * respondent or accused with numbers. Parts of an exhibit are sub-marked:
 * "A-1", "A-2" under a letter, "1-a", "1-b" under a number, alternating deeper.
 */
class ExhibitMarkings
{
    /** Client roles that present evidence first and mark with letters. */
    private const LETTER_ROLES = ['plaintiff', 'petitioner', 'complainant', 'appellant'];

    public function usesLetters(Matter $matter, string $side): bool
    {
        $criminal = strcasecmp((string) $matter->case_type, 'Criminal') === 0;
        // In a criminal case the prosecution (with the private complainant) marks with letters.
        $clientLetters = $criminal
            ? $matter->client_role !== 'accused'
            : in_array($matter->client_role ?: 'plaintiff', self::LETTER_ROLES, true);

        return $side === Exhibit::OURS ? $clientLetters : ! $clientLetters;
    }

    /** The next free marking for a side, or the next sub-marking under a parent exhibit. */
    public function next(Matter $matter, string $side, ?string $parent = null): string
    {
        $taken = Exhibit::where('matter_id', $matter->id)->where('side', $side)->pluck('marking')->map(fn ($m) => mb_strtoupper($m))->flip();
        $letters = $this->usesLetters($matter, $side);

        for ($i = 1; ; $i++) {
            $candidate = $parent === null
                ? ($letters ? $this->letter($i) : (string) $i)
                : $parent.'-'.($this->endsInNumber($parent) ? $this->subLetter($i) : (string) $i);
            if (! $taken->has(mb_strtoupper($candidate))) {
                return $candidate;
            }
        }
    }

    /**
     * Exhibits in marking order: "A", "A-1", "A-2", "B", ..., "Z", "AA"; "1", "1-a", "2", ..., "10".
     *
     * @param  Collection<int, Exhibit>  $exhibits
     * @return Collection<int, Exhibit>
     */
    public function sort(Collection $exhibits): Collection
    {
        return $exhibits->sortBy([
            fn ($a, $b) => strcmp($a->side === Exhibit::OURS ? '0' : '1', $b->side === Exhibit::OURS ? '0' : '1'),
            fn ($a, $b) => $this->compare($a->marking, $b->marking),
        ])->values();
    }

    public function compare(string $a, string $b): int
    {
        $pa = explode('-', mb_strtoupper($a));
        $pb = explode('-', mb_strtoupper($b));
        for ($i = 0; $i < max(count($pa), count($pb)); $i++) {
            if (! isset($pa[$i])) {
                return -1;
            }
            if (! isset($pb[$i])) {
                return 1;
            }
            $cmp = [$this->rank($pa[$i]), $pa[$i]] <=> [$this->rank($pb[$i]), $pb[$i]];
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    /** "A" = 1, "Z" = 26, "AA" = 27; numbers as themselves. */
    private function rank(string $part): int
    {
        if (ctype_digit($part)) {
            return (int) $part;
        }
        if (preg_match('/^([A-Z])\1*$/', $part)) {
            return (strlen($part) - 1) * 26 + ord($part[0]) - 64;
        }

        return PHP_INT_MAX;
    }

    /** 1 -> "A", 26 -> "Z", 27 -> "AA", 28 -> "BB". */
    private function letter(int $n): string
    {
        return str_repeat(chr(65 + ($n - 1) % 26), intdiv($n - 1, 26) + 1);
    }

    /** 1 -> "a", 27 -> "aa". */
    private function subLetter(int $n): string
    {
        return mb_strtolower($this->letter($n));
    }

    /** Sub-markings alternate: "A" -> "A-1" -> "A-1-a"; "1" -> "1-a" -> "1-a-1". */
    private function endsInNumber(string $marking): bool
    {
        $parts = explode('-', $marking);

        return ctype_digit(end($parts));
    }
}
