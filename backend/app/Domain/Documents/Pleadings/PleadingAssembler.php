<?php

namespace App\Domain\Documents\Pleadings;

use App\Domain\Matters\Enums\PartyRole;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Models\User;

/**
 * Assembles a Philippine pleading as plain text: the caption from the
 * matter, the title, the body, the prayer and signature block that Rule 7
 * requires, and, as needed, the verification, the certification against
 * forum shopping, the explanation of service and the copy furnished list.
 *
 * The layout is plain text a lawyer can edit; DocxWriter turns it into a
 * properly formatted Word file (centered heading, caption table).
 */
class PleadingAssembler
{
    public const TYPES = [
        'complaint' => ['label' => 'Complaint', 'initiatory' => true],
        'petition' => ['label' => 'Petition', 'initiatory' => true],
        'answer' => ['label' => 'Answer', 'initiatory' => false],
        'motion' => ['label' => 'Motion', 'initiatory' => false],
        'manifestation' => ['label' => 'Manifestation', 'initiatory' => false],
        'comment' => ['label' => 'Comment / Opposition', 'initiatory' => false],
        'position_paper' => ['label' => 'Position Paper', 'initiatory' => false],
        'memorandum' => ['label' => 'Memorandum', 'initiatory' => false],
        'formal_offer' => ['label' => 'Formal Offer of Evidence', 'initiatory' => false],
    ];

    public const CLIENT_ROLES = ['plaintiff', 'defendant', 'petitioner', 'respondent', 'complainant', 'accused', 'appellant', 'appellee'];

    private const OPPOSITE = [
        'plaintiff' => 'defendant', 'defendant' => 'plaintiff',
        'petitioner' => 'respondent', 'respondent' => 'petitioner',
        'complainant' => 'respondent', 'accused' => 'plaintiff',
        'appellant' => 'appellee', 'appellee' => 'appellant',
    ];

    /** Party roles that come first in the caption. */
    private const FIRST = ['plaintiff', 'petitioner', 'complainant', 'appellant'];

    private const WIDTH = 78;

    /**
     * @param  array{type: string, title?: string|null, body?: string|null, prayer?: string|null, place?: string|null,
     *               verification?: bool, certification?: bool, service?: bool}  $options
     */
    public function assemble(Matter $matter, User $counsel, array $options): string
    {
        $matter->loadMissing(['client', 'parties']);
        $firm = Firm::findOrFail($matter->firm_id);
        $type = self::TYPES[$options['type']] ?? self::TYPES['motion'];
        $title = mb_strtoupper(trim($options['title'] ?? '') ?: $type['label']);
        $initiatory = $type['initiatory'];

        $parts = [
            $this->heading($matter),
            $this->caption($matter),
            $this->center($title),
            $this->salutation($matter, $title),
            trim($options['body'] ?? '') ?: $this->bodyPlaceholder($options['type']),
            'PRAYER',
            trim($options['prayer'] ?? '') ?: 'WHEREFORE, it is respectfully prayed that this Honorable Court [state the relief sought].'
                ."\n\nOther reliefs just and equitable under the premises are likewise prayed for.",
            $this->submission($firm, $options['place'] ?? null),
            $this->signature($firm, $counsel, $matter),
        ];

        if ($options['verification'] ?? $initiatory) {
            $parts[] = $this->verification($matter, $title);
        }
        if ($options['certification'] ?? $initiatory) {
            $parts[] = $this->certification($matter);
        }
        if ($options['service'] ?? true) {
            $parts[] = $this->service($matter);
            $parts[] = $this->copyFurnished($matter);
        }

        return implode("\n\n", array_filter($parts, fn ($p) => $p !== ''))."\n";
    }

    /** "Republic of the Philippines" and the court, centered. */
    private function heading(Matter $matter): string
    {
        $lines = ['REPUBLIC OF THE PHILIPPINES'];
        $court = trim((string) $matter->court);
        if ($court === '') {
            $lines[] = '[NAME OF COURT]';
        } else {
            // "Regional Trial Court, Manila" -> court on one line, the seat below.
            [$name, $seat] = array_pad(array_map('trim', explode(',', $court, 2)), 2, null);
            $lines[] = mb_strtoupper($name);
            if ($matter->court_branch) {
                $lines[] = $matter->court_branch.($seat ? ", {$seat}" : '');
            } elseif ($seat) {
                $lines[] = $seat;
            }
        }

        return implode("\n", array_map(fn ($l) => $this->centerLine($l), $lines));
    }

    /**
     * The parties on the left, the docket number and nature of the action on
     * the right, closed by the customary "x - - - x" line.
     */
    public function caption(Matter $matter): string
    {
        $clientRole = in_array($matter->client_role, self::CLIENT_ROLES, true) ? $matter->client_role : 'plaintiff';
        $criminal = strcasecmp((string) $matter->case_type, 'Criminal') === 0;
        $client = mb_strtoupper((string) $matter->client?->name);
        $adverse = $matter->parties->where('role', PartyRole::AdverseParty)->pluck('name')->map(fn ($n) => mb_strtoupper($n))->values()->all() ?: ['[OPPOSING PARTY]'];

        if ($criminal) {
            // Criminal cases are prosecuted in the name of the People.
            $accused = $clientRole === 'accused' ? [$client] : $adverse;
            [$firstNames, $firstRole, $secondNames, $secondRole] = [['PEOPLE OF THE PHILIPPINES'], 'Plaintiff', $accused, 'Accused'];
        } else {
            $otherRole = self::OPPOSITE[$clientRole];
            $clientFirst = in_array($clientRole, self::FIRST, true);
            [$firstNames, $firstRole, $secondNames, $secondRole] = $clientFirst
                ? [[$client], $clientRole, $adverse, $otherRole]
                : [$adverse, $otherRole, [$client], $clientRole];
            $firstRole = ucfirst($firstRole);
            $secondRole = ucfirst($secondRole);
        }

        $label = $this->docketLabel((string) $matter->case_type);
        $right = [
            ($matter->case_number ? "{$label} {$matter->case_number}" : "{$label} ______"),
            'For: '.($matter->nature_of_action ?: '[Nature of the action]'),
        ];

        // Each name ends with a comma; the role follows, indented, pluralized
        // for several parties: "Plaintiffs," above the versus, "Defendant." below.
        $side = fn (array $names, string $role, string $end) => [
            ...array_map(fn ($n) => "{$n},", $names),
            str_repeat(' ', 15).$role.(count($names) > 1 ? 's' : '').$end,
        ];
        $left = [...$side($firstNames, $firstRole, ','), '', '     - versus -', '', ...$side($secondNames, $secondRole, '.')];

        // The docket number beside the first party's role, the nature of the
        // action beside "versus" (both lines have text on the left, so Word
        // export can pair them into a two-column caption).
        $rows = [];
        $versusAt = array_search('     - versus -', $left, true);
        foreach ($left as $i => $line) {
            $r = match ($i - $versusAt) {
                -2 => $right[0], 0 => $right[1], default => null
            };
            $rows[] = $r === null ? $line : str_pad($line, 44).'      '.$r;
        }
        $rows[] = 'x'.str_repeat(' -', 20).' x';

        return implode("\n", $rows);
    }

    public function docketLabel(string $caseType): string
    {
        return match (mb_strtolower($caseType)) {
            'criminal' => 'Criminal Case No.',
            'labor' => 'NLRC Case No.',
            'land registration' => 'LRC Case No.',
            'special proceedings', 'estate / probate' => 'Sp. Proc. No.',
            'administrative' => 'Adm. Case No.',
            'tax' => 'CTA Case No.',
            'civil', 'family', 'annulment', 'corporate', 'intellectual property' => 'Civil Case No.',
            default => 'Case No.',
        };
    }

    private function salutation(Matter $matter, string $title): string
    {
        $role = in_array($matter->client_role, self::CLIENT_ROLES, true) ? $matter->client_role : 'plaintiff';
        $who = mb_strtoupper((string) $matter->client?->name);

        return ucfirst($role)." {$who}, by counsel, unto this Honorable Court, respectfully states:";
    }

    private function bodyPlaceholder(string $type): string
    {
        return match ($type) {
            'complaint', 'petition' => "THE PARTIES\n\n1. [Allegations about the parties.]\n\nCAUSE OF ACTION\n\n2. [Allegations of fact constituting the cause of action.]",
            'answer' => "ADMISSIONS AND DENIALS\n\n1. [Specific admissions and denials.]\n\nAFFIRMATIVE DEFENSES\n\n2. [Affirmative defenses.]",
            'motion' => "1. [Grounds for the motion.]\n\n2. [Supporting facts and law.]",
            'formal_offer' => "The following exhibits are offered in evidence:\n\nExhibit \"[A]\" — [Description]\n    Purpose: [What it is offered to prove.]",
            default => '1. [Statement.]',
        };
    }

    private function submission(Firm $firm, ?string $place): string
    {
        $place = trim((string) $place) ?: ($this->cityOf($firm->address) ?? '[City]');

        return 'Respectfully submitted.'."\n\n".$place.', Philippines, '.now()->format('F j, Y').'.';
    }

    /** Rule 7, Sec. 3: counsel's address, roll, IBP, PTR and MCLE numbers, and e-mail. */
    private function signature(Firm $firm, User $counsel, Matter $matter): string
    {
        $indent = str_repeat(' ', 40);
        $lines = [
            mb_strtoupper($firm->name),
            'Counsel for the '.ucfirst(in_array($matter->client_role, self::CLIENT_ROLES, true) ? $matter->client_role : 'plaintiff'),
            ...array_map('trim', explode("\n", wordwrap((string) ($firm->address ?: '[Firm address]'), 38))),
            $firm->phone ? "Tel. {$firm->phone}" : null,
            '',
            'By:',
            '',
            '',
            mb_strtoupper($counsel->name),
            'Roll of Attorneys No. '.($counsel->roll_number ?: '______'),
            'IBP No. '.($counsel->ibp_number ?: '______'),
            'PTR No. '.($counsel->ptr_number ?: '______'),
            'MCLE Compliance No. '.($counsel->mcle_compliance_number ?: '______'),
            $counsel->email,
        ];

        return implode("\n", array_map(fn ($l) => $l === '' ? '' : $indent.$l, array_filter($lines, fn ($l) => $l !== null)));
    }

    private function verification(Matter $matter, string $title): string
    {
        $client = (string) $matter->client?->name;
        $pleading = mb_strtolower($title);

        return $this->center('VERIFICATION')."\n\n"
            ."I, {$client}, of legal age, Filipino, after having been duly sworn in accordance with law, depose and state that:\n\n"
            .'1. I am the '.(in_array($matter->client_role, self::CLIENT_ROLES, true) ? $matter->client_role : 'plaintiff')." in this case and have caused the preparation of the foregoing {$pleading};\n\n"
            ."2. I have read it, and the allegations in it are true and correct of my personal knowledge or based on authentic records;\n\n"
            ."3. It is not filed to harass, cause unnecessary delay, or needlessly increase the cost of litigation; and\n\n"
            ."4. The factual allegations have evidentiary support or, if specifically so identified, will likewise have evidentiary support after a reasonable opportunity for discovery.\n\n"
            .str_repeat(' ', 40)."_______________________\n".str_repeat(' ', 40).mb_strtoupper($client)."\n".str_repeat(' ', 40)."Affiant\n\n"
            .$this->jurat();
    }

    private function certification(Matter $matter): string
    {
        $client = (string) $matter->client?->name;

        return $this->center('CERTIFICATION AGAINST FORUM SHOPPING')."\n\n"
            ."I, {$client}, of legal age, Filipino, after having been duly sworn in accordance with law, certify that:\n\n"
            ."1. I have not commenced any other action or proceeding involving the same issues in the Supreme Court, the Court of Appeals, or any other tribunal or agency;\n\n"
            ."2. To the best of my knowledge, no such other action or proceeding is pending there; and\n\n"
            ."3. If I learn that the same or a similar action or proceeding has been filed or is pending, I will report that fact within five (5) days to this Honorable Court.\n\n"
            .str_repeat(' ', 40)."_______________________\n".str_repeat(' ', 40).mb_strtoupper($client)."\n".str_repeat(' ', 40)."Affiant\n\n"
            .$this->jurat();
    }

    private function jurat(): string
    {
        return 'SUBSCRIBED AND SWORN to before me this ____ day of ______________ '.now()->year.' at ______________, affiant exhibiting to me competent evidence of identity ______________ issued on ______________ at ______________.'
            ."\n\nDoc. No. ____;\nPage No. ____;\nBook No. ____;\nSeries of ".now()->year.'.';
    }

    /** Rule 13: service by electronic means or personal service, and why. */
    private function service(Matter $matter): string
    {
        return $this->center('EXPLANATION')."\n\n"
            .'A copy of this pleading was served on opposing counsel by electronic mail, and by registered mail with return card, personal service not being practicable due to distance and lack of personnel.';
    }

    private function copyFurnished(Matter $matter): string
    {
        $counsel = $matter->parties->filter(fn ($p) => in_array($p->role, [PartyRole::AdverseCounsel, PartyRole::AdverseParty], true) && ($p->role === PartyRole::AdverseCounsel || $p->counsel_name))
            ->map(fn ($p) => $p->role === PartyRole::AdverseCounsel ? $p->name : $p->counsel_name)
            ->unique()->values();

        $lines = $counsel->isEmpty() ? ['[OPPOSING COUNSEL]', '[Address / e-mail]'] : $counsel->map(fn ($name) => mb_strtoupper($name)."\n[Address / e-mail]")->all();

        return "Copy furnished:\n\n".implode("\n\n", $lines);
    }

    private function center(string $text): string
    {
        return $this->centerLine($text);
    }

    private function centerLine(string $text): string
    {
        $pad = max(0, intdiv(self::WIDTH - mb_strlen($text), 2));

        return str_repeat(' ', $pad).$text;
    }

    private function cityOf(?string $address): ?string
    {
        if (! $address) {
            return null;
        }
        $parts = array_map('trim', explode(',', $address));

        return end($parts) ?: null;
    }
}
