<?php

namespace App\Domain\Documents\Search;

use App\Domain\Documents\Models\MatterFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Searches uploaded files by name, description and extracted text.
 *
 * PostgreSQL uses the full-text index (every word must match; each word
 * also matches as a prefix, so "affid" finds "affidavit"). Other databases
 * fall back to substring matching. Snippets mark hits with ⟦ and ⟧.
 */
class FileSearch
{
    public const MARK_START = '⟦';

    public const MARK_END = '⟧';

    /** Stored, indexed tsvector of name, description and text (PostgreSQL keeps it current). */
    private const DOCUMENT = 'search_vector';

    /** Every column except the (potentially large) extracted text. */
    public const COLUMNS = [
        'id', 'firm_id', 'matter_id', 'uploaded_by', 'original_name', 'path', 'mime_type', 'size_bytes', 'sha256',
        'description', 'shared_with_client', 'scan_status', 'scanned_at', 'text_status', 'text_source', 'created_at', 'updated_at', 'deleted_at',
    ];

    /** @return list<string> */
    public function terms(string $search): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_slice($words, 0, 10)));
    }

    /** @return Builder<MatterFile> */
    public function query(string $search, ?int $matterId = null): Builder
    {
        $terms = $this->terms($search);
        $query = MatterFile::query()
            ->when($matterId, fn ($q) => $q->where('matter_id', $matterId))
            ->when($terms === [], fn ($q) => $q->whereRaw('1 = 0'));

        if ($terms === []) {
            return $query->select(self::COLUMNS);
        }

        if (DB::getDriverName() === 'pgsql') {
            // Terms are letters and digits only, so the tsquery syntax is safe.
            $tsquery = implode(' & ', array_map(fn ($term) => "{$term}:*", $terms));
            $options = 'StartSel='.self::MARK_START.', StopSel='.self::MARK_END.', MaxWords=30, MinWords=12, MaxFragments=2, FragmentDelimiter=" … "';

            return $query
                ->select(self::COLUMNS)
                ->selectRaw("ts_headline('simple', coalesce(content_text, ''), to_tsquery('simple', ?), ?) as snippet", [$tsquery, $options])
                // Names are in the vector split into words, so the index finds them too.
                ->whereRaw(self::DOCUMENT." @@ to_tsquery('simple', ?)", [$tsquery])
                ->orderByRaw('ts_rank('.self::DOCUMENT.", to_tsquery('simple', ?)) desc", [$tsquery])
                ->latest('id');
        }

        $query->select([...self::COLUMNS, 'content_text']);
        foreach ($terms as $term) {
            $query->where(fn ($q) => $q
                ->whereLike('original_name', "%{$term}%")
                ->orWhereLike('description', "%{$term}%")
                ->orWhereLike('content_text', "%{$term}%"));
        }

        return $query->latest('id');
    }

    /**
     * Fallback snippet (non-PostgreSQL): the text around the first hit,
     * with every hit marked. Drops the full text from the model.
     *
     * @param  list<string>  $terms
     */
    public function withSnippet(MatterFile $file, array $terms): MatterFile
    {
        $attributes = $file->getAttributes();

        if (array_key_exists('snippet', $attributes) || ! array_key_exists('content_text', $attributes)) {
            return $file;
        }

        $text = (string) $attributes['content_text'];
        $snippet = '';
        $first = null;

        foreach ($terms as $term) {
            $at = mb_stripos($text, $term);
            if ($at !== false && ($first === null || $at < $first)) {
                $first = $at;
            }
        }

        if ($first !== null) {
            $start = max(0, $first - 100);
            $snippet = ($start > 0 ? '… ' : '').mb_substr($text, $start, 240).($start + 240 < mb_strlen($text) ? ' …' : '');
            $pattern = '/('.implode('|', array_map(fn ($t) => preg_quote($t, '/'), $terms)).')/iu';
            $snippet = preg_replace($pattern, self::MARK_START.'$1'.self::MARK_END, $snippet) ?? $snippet;
        }

        unset($attributes['content_text']);
        $attributes['snippet'] = $snippet;

        return $file->setRawAttributes($attributes, true);
    }
}
