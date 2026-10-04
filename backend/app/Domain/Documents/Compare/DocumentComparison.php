<?php

namespace App\Domain\Documents\Compare;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Models\MatterFile;
use Illuminate\Validation\ValidationException;

/**
 * Compares two sides of a document: any two of its saved versions, or a
 * version against a file in the same matter (typically the other side's
 * marked-up draft, read from the text extracted when it was uploaded).
 *
 * A side is written "v:{version number}" or "f:{matter file id}".
 */
class DocumentComparison
{
    public function __construct(private readonly TextDiff $diff) {}

    /**
     * @return array{base: array, other: array, segments: list<array{type: string, text: string}>, inserted_words: int, deleted_words: int}
     */
    public function compare(Document $document, string $base, string $other): array
    {
        $a = $this->side($document, $base, 'base');
        $b = $this->side($document, $other, 'other');

        // Extracted file text has one paragraph per line; compare a version
        // against a file in the same shape, or every blank line is a change.
        if ($a['kind'] === 'file' || $b['kind'] === 'file') {
            $a['content'] = $this->paragraphs($a['content']);
            $b['content'] = $this->paragraphs($b['content']);
        }

        return ['base' => $a, 'other' => $b, ...$this->diff->compare($a['content'], $b['content'])];
    }

    /** Versions and text-bearing files of the matter, for choosing sides. */
    public function sources(Document $document): array
    {
        return [
            'versions' => $document->versions()->with('creator:id,name')->get()->map(fn (DocumentVersion $v) => [
                'value' => "v:{$v->version_number}",
                'label' => "Version {$v->version_number}",
                'detail' => trim(($v->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? '').($v->creator ? " · {$v->creator->name}" : '')),
                'summary' => $v->change_summary,
            ])->values(),
            'files' => $document->matter_id === null ? [] : MatterFile::query()
                ->where('matter_id', $document->matter_id)
                ->where('text_status', 'extracted')
                ->latest()
                ->limit(100)
                ->get(['id', 'original_name', 'created_at'])
                ->map(fn (MatterFile $f) => [
                    'value' => "f:{$f->id}",
                    'label' => $f->original_name,
                    'detail' => 'Uploaded '.$f->created_at?->timezone('Asia/Manila')->format('M j, Y'),
                ])->values(),
        ];
    }

    private function side(Document $document, string $ref, string $field): array
    {
        if (preg_match('/^v:(\d+)$/', $ref, $m)) {
            $version = $document->versions()->with('creator:id,name')->where('version_number', (int) $m[1])->first();
            if ($version === null) {
                throw ValidationException::withMessages([$field => "This document has no version {$m[1]}."]);
            }

            return [
                'kind' => 'version',
                'ref' => $ref,
                'label' => "Version {$version->version_number}",
                'detail' => trim(($version->created_at?->timezone('Asia/Manila')->format('F j, Y g:i A') ?? '').($version->creator ? ", by {$version->creator->name}" : '')),
                'content' => (string) $version->content,
            ];
        }

        if (preg_match('/^f:(\d+)$/', $ref, $m)) {
            $file = MatterFile::query()->where('matter_id', $document->matter_id)->find((int) $m[1]);
            if ($file === null || $document->matter_id === null) {
                throw ValidationException::withMessages([$field => 'Choose a file from this matter.']);
            }
            $text = MatterFile::query()->whereKey($file->id)->value('content_text');
            if ($file->text_status !== 'extracted' || blank($text)) {
                throw ValidationException::withMessages([$field => "No text could be read from {$file->original_name}. Upload it as Word (.docx) or a PDF with selectable text."]);
            }

            return [
                'kind' => 'file',
                'ref' => $ref,
                'label' => $file->original_name,
                'detail' => 'Uploaded '.$file->created_at?->timezone('Asia/Manila')->format('F j, Y g:i A'),
                'content' => (string) $text,
            ];
        }

        throw ValidationException::withMessages([$field => 'Choose a version or a file to compare.']);
    }

    private function paragraphs(string $text): string
    {
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', str_replace("\r\n", "\n", $text)) ?? $text;

        return trim(preg_replace("/\s*\n\s*/u", "\n", $text) ?? $text);
    }
}
