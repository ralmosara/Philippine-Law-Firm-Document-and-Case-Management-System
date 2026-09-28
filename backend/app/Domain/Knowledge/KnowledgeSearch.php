<?php

namespace App\Domain\Knowledge;

use App\Domain\Knowledge\Models\KnowledgeItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Searches the knowledge bank: PostgreSQL full-text search with prefix
 * matching ("negl" finds "negligence"), ranked, with a highlighted passage;
 * a plain LIKE search elsewhere. G.R. numbers match as typed ("G.R. No.
 * 123456" or just "123456").
 */
class KnowledgeSearch
{
    public const MARK_START = '⟦';

    public const MARK_END = '⟧';

    private const DOCUMENT = "to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(citation, '') || ' ' || coalesce(doctrine, '') || ' ' || coalesce(body, ''))";

    /** @return list<string> */
    public function terms(string $search): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // "G.R. No." carries no meaning in a search; the number does.
        $words = array_values(array_filter($words, fn ($w) => ! in_array($w, ['g', 'r', 'gr', 'no'], true)));

        return array_values(array_unique(array_slice($words, 0, 10)));
    }

    public function query(string $search): Builder
    {
        $terms = $this->terms($search);
        $query = KnowledgeItem::query();
        if ($terms === []) {
            return $query->latest('updated_at');
        }

        if (DB::getDriverName() === 'pgsql') {
            // Terms are letters and digits only, so the tsquery syntax is safe.
            $tsquery = implode(' & ', array_map(fn ($t) => "{$t}:*", $terms));
            $options = 'StartSel='.self::MARK_START.', StopSel='.self::MARK_END.', MaxWords=30, MinWords=12, MaxFragments=2, FragmentDelimiter=" … "';

            return $query
                ->select('knowledge_items.*')
                ->selectRaw("ts_headline('simple', coalesce(doctrine, '') || ' ' || coalesce(body, ''), to_tsquery('simple', ?), ?) as snippet", [$tsquery, $options])
                ->whereRaw(self::DOCUMENT." @@ to_tsquery('simple', ?)", [$tsquery])
                ->orderByRaw('ts_rank('.self::DOCUMENT.", to_tsquery('simple', ?)) desc", [$tsquery])
                ->latest('updated_at');
        }

        foreach ($terms as $term) {
            $query->where(fn ($q) => $q
                ->whereLike('title', "%{$term}%")
                ->orWhereLike('citation', "%{$term}%")
                ->orWhereLike('doctrine', "%{$term}%")
                ->orWhereLike('body', "%{$term}%"));
        }

        return $query->latest('updated_at');
    }

    /** A highlighted passage for databases without ts_headline. */
    public function snippet(KnowledgeItem $item, array $terms): ?string
    {
        if (array_key_exists('snippet', $item->getAttributes())) {
            return $item->getAttributes()['snippet'] ?: null;
        }
        $text = trim(($item->doctrine ? "{$item->doctrine} " : '').(string) $item->body);
        if ($text === '') {
            return null;
        }
        $first = null;
        foreach ($terms as $term) {
            $at = mb_stripos($text, $term);
            if ($at !== false && ($first === null || $at < $first)) {
                $first = $at;
            }
        }
        $start = $first === null ? 0 : max(0, $first - 100);
        $snippet = ($start > 0 ? '… ' : '').mb_substr($text, $start, 240).($start + 240 < mb_strlen($text) ? ' …' : '');
        if ($terms !== []) {
            $pattern = '/('.implode('|', array_map(fn ($t) => preg_quote($t, '/'), $terms)).')/iu';
            $snippet = preg_replace($pattern, self::MARK_START.'$1'.self::MARK_END, $snippet) ?? $snippet;
        }

        return $snippet;
    }
}
