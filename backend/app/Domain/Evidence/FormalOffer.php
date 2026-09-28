<?php

namespace App\Domain\Evidence;

use App\Domain\Documents\Pleadings\PleadingAssembler;
use App\Domain\Evidence\Models\Exhibit;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The Formal Offer of Evidence (Rule 132, Secs. 34-35): each exhibit with its
 * description and the purpose it is offered for, wrapped in the pleading
 * format. Under the 2019 amendments the offer is made orally unless the court
 * allows a written one; the same list serves as the lawyer's script.
 */
class FormalOffer
{
    public function __construct(private readonly PleadingAssembler $assembler, private readonly ExhibitMarkings $markings) {}

    /** Our exhibits not yet ruled on or withdrawn, in marking order. */
    public function offerable(Matter $matter): Collection
    {
        return $this->markings->sort(
            Exhibit::where('matter_id', $matter->id)->where('side', Exhibit::OURS)->whereIn('status', ['marked', 'offered'])->get()
        );
    }

    /** @param  Collection<int, Exhibit>  $exhibits */
    public function assemble(Matter $matter, User $counsel, Collection $exhibits, array $options = []): string
    {
        $exhibits = $this->markings->sort($exhibits);
        $role = in_array($matter->client_role, PleadingAssembler::CLIENT_ROLES, true) ? $matter->client_role : 'plaintiff';
        $criminal = strcasecmp((string) $matter->case_type, 'Criminal') === 0 && $role !== 'accused';

        $items = $exhibits->map(function (Exhibit $e) {
            $depth = substr_count($e->marking, '-');
            $indent = str_repeat('    ', $depth);
            $lines = ["{$indent}Exhibit \"{$e->marking}\" — ".trim($e->description)];
            if ($e->witness) {
                $lines[] = "{$indent}    Identified by: {$e->witness}";
            }
            $lines[] = "{$indent}    Purpose: ".(trim((string) $e->purpose) ?: '[State the purpose for which it is offered.]');

            return implode("\n", $lines);
        });

        $body = ($criminal ? 'The prosecution' : ucfirst($role))
            .', having presented its testimonial evidence, offers the following documentary and object exhibits, which were marked and identified during the trial, for the purposes stated:'
            ."\n\n".$items->implode("\n\n");

        $prayer = 'WHEREFORE, it is respectfully prayed that '.$this->list($exhibits)
            .' be admitted in evidence for the purposes for which they are offered, and made part of the records of this case.'
            ."\n\nOther reliefs just and equitable under the premises are likewise prayed for.";

        return $this->assembler->assemble($matter, $counsel, [
            'type' => 'formal_offer',
            'body' => $body,
            'prayer' => $prayer,
            'place' => $options['place'] ?? null,
            'verification' => false,
            'certification' => false,
            'service' => true,
        ]);
    }

    /** Exhibits "A", "A-1" and "B". */
    private function list(Collection $exhibits): string
    {
        $marks = $exhibits->map(fn (Exhibit $e) => "\"{$e->marking}\"")->values()->all();
        if (count($marks) === 1) {
            return "Exhibit {$marks[0]}";
        }
        $last = array_pop($marks);

        return 'Exhibits '.implode(', ', $marks)." and {$last}";
    }
}
