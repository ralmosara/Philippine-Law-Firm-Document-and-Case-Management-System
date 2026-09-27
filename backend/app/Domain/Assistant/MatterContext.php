<?php

namespace App\Domain\Assistant;

use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Matter;
use Illuminate\Support\Str;

/**
 * Packs a matter's facts, drafted documents and uploaded files' text into
 * labelled sources for the assistant, within a size budget. Sources are
 * marked as data so text inside them is never treated as instructions.
 */
class MatterContext
{
    /** Per-source cap, so one long transcript cannot crowd out everything else. */
    private const PER_SOURCE_CHARS = 60_000;

    /**
     * @return array{text: string, sources: list<string>}
     */
    public function build(Matter $matter): array
    {
        $budget = (int) config('services.anthropic.context_chars', 400_000);
        $matter->loadMissing(['client', 'responsibleLawyer', 'parties']);
        $sources = ['M'];
        $parts = ['<source id="M" type="matter facts">'.$this->facts($matter).'</source>'];
        $used = strlen($parts[0]);

        $documents = Document::where('matter_id', $matter->id)->with('latestVersion')->latest('updated_at')->get();
        $files = MatterFile::where('matter_id', $matter->id)->whereNotNull('content_text')->latest('id')->get();

        $candidates = [
            ...$documents->map(fn (Document $d) => ['id' => "D{$d->id}", 'attrs' => 'type="document" title="'.e($d->title).'" status="'.$d->status->value.'" version="'.$d->current_version.'"', 'text' => (string) $d->latestVersion?->content]),
            ...$files->map(fn (MatterFile $f) => ['id' => "F{$f->id}", 'attrs' => 'type="uploaded file" name="'.e($f->original_name).'" uploaded="'.$f->created_at?->toDateString().'"'.($f->text_source === 'ocr' ? ' note="text recognised by OCR; may contain reading errors"' : ''), 'text' => (string) $f->content_text]),
        ];

        $omitted = 0;
        foreach ($candidates as $source) {
            $text = Str::limit($source['text'], self::PER_SOURCE_CHARS, "\n[… truncated]");
            $block = "<source id=\"{$source['id']}\" {$source['attrs']}>\n{$text}\n</source>";

            if ($used + strlen($block) > $budget) {
                $omitted++;

                continue;
            }

            $parts[] = $block;
            $sources[] = $source['id'];
            $used += strlen($block);
        }

        if ($omitted > 0) {
            $parts[] = "<note>{$omitted} older document(s)/file(s) were left out to fit the size limit.</note>";
        }

        return ['text' => implode("\n\n", $parts), 'sources' => $sources];
    }

    private function facts(Matter $matter): string
    {
        $deadlines = $matter->deadlines()->where('status', DeadlineStatus::Pending->value)->orderBy('due_date')->limit(20)->get()
            ->map(fn ($d) => "- {$d->due_date->toDateString()}".($d->due_time ? ' '.substr($d->due_time, 0, 5) : '')." · {$d->kind->value}: {$d->title}")
            ->implode("\n");

        $parties = $matter->parties->map(fn ($p) => "- {$p->role->label()}: {$p->name}".($p->counsel_name ? " (counsel: {$p->counsel_name})" : ''))->implode("\n");

        return implode("\n", array_filter([
            "Reference: {$matter->reference}",
            "Title: {$matter->title}",
            "Client: {$matter->client?->name}",
            "Case type: {$matter->case_type}",
            $matter->case_number ? "Case number: {$matter->case_number}" : null,
            $matter->court ? 'Court: '.collect([$matter->court, $matter->court_branch])->filter()->implode(', ') : null,
            $matter->judge ? "Presiding judge: {$matter->judge}" : null,
            "Stage: {$matter->status->label()}",
            "Opened: {$matter->opened_at?->toDateString()}",
            $matter->responsibleLawyer ? "Responsible lawyer: {$matter->responsibleLawyer->name}" : null,
            $matter->description ? "Summary: {$matter->description}" : null,
            $parties !== '' ? "Parties:\n{$parties}" : null,
            $deadlines !== '' ? "Upcoming deadlines and hearings:\n{$deadlines}" : 'Upcoming deadlines and hearings: none recorded',
            'Today: '.now('Asia/Manila')->toDateString(),
        ]));
    }
}
