<?php

namespace App\Domain\Business;

use App\Domain\Business\Models\Prospect;
use App\Domain\Business\Models\ProspectEvent;
use App\Domain\Business\Notifications\ProspectFollowUp;
use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Domain\Compliance\Services\ConflictChecker;
use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Matters\Actions\OpenMatter;
use App\Domain\Matters\Enums\PartyRole;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Domain\Prescription\Prescriptions;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The business development pipeline. Each prospect is conflict-checked
 * (name and opposing parties) when added, moves through the stages with
 * every move recorded, and is converted into a client and matter once the
 * engagement is signed and any flagged conflict resolved.
 */
class Pipeline
{
    public function __construct(
        private readonly ConflictChecker $conflicts,
        private readonly OpenMatter $openMatter,
        private readonly TenantContext $tenant,
    ) {}

    public function create(array $data, User $by): Prospect
    {
        return DB::transaction(function () use ($data, $by) {
            $data['opposing_parties'] = array_values(array_filter(array_map('trim', $data['opposing_parties'] ?? [])));
            $prospect = Prospect::create([
                ...$data,
                'firm_id' => $by->firm_id,
                'owner_id' => $data['owner_id'] ?? $by->id,
                'conflict_check_ids' => $this->check($by, $data['name'], $data['opposing_parties']),
            ]);
            $this->log($prospect, 'stage', $by, null, 'lead', 'Added');

            return $prospect;
        });
    }

    /** Track an online intake request in the pipeline, reusing its conflict checks. */
    public function fromIntake(IntakeRequest $intake, User $by): Prospect
    {
        if ($existing = Prospect::where('intake_request_id', $intake->id)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($intake, $by) {
            $prospect = Prospect::create([
                'firm_id' => $intake->firm_id,
                'name' => $intake->name,
                'client_type' => $intake->client_type,
                'email' => $intake->email,
                'phone' => $intake->phone,
                'source' => 'website',
                'case_type' => $intake->case_type,
                'description' => $intake->description,
                'opposing_parties' => $intake->opposing_parties ?? [],
                'owner_id' => $intake->assigned_lawyer_id ?? $by->id,
                'intake_request_id' => $intake->id,
                'conflict_check_ids' => $intake->conflict_check_ids ?? [],
            ]);
            $prospect->forceFill(['stage' => $intake->consultation_at ? 'consultation' : 'lead'])->save();
            $this->log($prospect, 'stage', $by, null, $prospect->stage, 'From an online intake request');

            return $prospect;
        });
    }

    /** New names to check when the prospect or its opposing parties change. */
    public function recheck(Prospect $prospect, User $by): void
    {
        $checked = $prospect->conflictChecks()->pluck('search_term')->map(fn ($t) => mb_strtolower($t))->all();
        $names = collect([$prospect->name, ...($prospect->opposing_parties ?? [])])->filter(fn ($n) => ! in_array(mb_strtolower(trim($n)), $checked, true));
        if ($names->isNotEmpty()) {
            $prospect->forceFill(['conflict_check_ids' => [...($prospect->conflict_check_ids ?? []), ...$this->check($by, $names->first(), $names->slice(1)->all())]])->save();
        }
    }

    public function move(Prospect $prospect, string $stage, User $by, ?string $note = null, ?string $lostReason = null): void
    {
        if (! $prospect->isOpen()) {
            throw ValidationException::withMessages(['stage' => 'This prospect is closed.']);
        }
        if ($stage === 'won') {
            throw ValidationException::withMessages(['stage' => 'Convert the prospect into a client and matter to mark it engaged.']);
        }
        if ($stage === 'lost' && ! trim((string) $lostReason)) {
            throw ValidationException::withMessages(['lost_reason' => 'Say why it was lost; the report groups by reason.']);
        }

        $from = $prospect->stage;
        $today = CarbonImmutable::today()->toDateString();
        $prospect->forceFill([
            'stage' => $stage,
            'proposal_sent_on' => $stage === 'proposal' ? ($prospect->proposal_sent_on ?? $today) : $prospect->proposal_sent_on,
            'engagement_sent_on' => $stage === 'engagement_sent' ? ($prospect->engagement_sent_on ?? $today) : $prospect->engagement_sent_on,
            'lost_reason' => $stage === 'lost' ? $lostReason : null,
            'closed_at' => $stage === 'lost' ? now() : null,
            'next_step' => $stage === 'lost' ? null : $prospect->next_step,
            'next_step_on' => $stage === 'lost' ? null : $prospect->next_step_on,
        ])->save();

        $this->log($prospect, 'stage', $by, $from, $stage, $stage === 'lost' ? trim("{$lostReason}\n\n".($note ?? '')) : $note);
    }

    public function note(Prospect $prospect, string $type, string $body, User $by): ProspectEvent
    {
        return $this->log($prospect, $type, $by, null, null, $body);
    }

    /**
     * The engagement is signed: create the client (or reuse one with the
     * same email) and open the matter, with the opposing parties recorded.
     */
    public function convert(Prospect $prospect, User $by, ?string $title = null, ?int $lawyerId = null): Matter
    {
        if (! $prospect->isOpen()) {
            throw ValidationException::withMessages(['stage' => 'This prospect is closed.']);
        }
        foreach ($prospect->conflictChecks() as $check) {
            if ($check->status === ConflictCheckStatus::Flagged) {
                throw ValidationException::withMessages(['conflicts' => "Resolve the flagged conflict check for \"{$check->search_term}\" (Compliance → Conflict checks) first."]);
            }
            if ($check->status === ConflictCheckStatus::Declined) {
                throw ValidationException::withMessages(['conflicts' => "A conflict check for \"{$check->search_term}\" was declined; this engagement cannot proceed."]);
            }
        }

        return DB::transaction(function () use ($prospect, $by, $title, $lawyerId) {
            $client = ($prospect->email ? Client::query()->whereRaw('lower(email) = ?', [mb_strtolower($prospect->email)])->first() : null)
                ?? Client::create([
                    'firm_id' => $prospect->firm_id,
                    'type' => $prospect->client_type,
                    'name' => $prospect->organization && $prospect->client_type === 'corporate' ? $prospect->organization : $prospect->name,
                    'email' => $prospect->email,
                    'phone' => $prospect->phone,
                ]);

            $matter = $this->openMatter->execute([
                'firm_id' => $prospect->firm_id,
                'client_id' => $client->id,
                'title' => $title ?: "{$client->name}: ".($prospect->case_type ?: 'New matter'),
                'case_type' => $prospect->case_type,
                'description' => $prospect->description,
                'responsible_lawyer_id' => $lawyerId ?? $prospect->owner_id,
            ], $by, array_map(fn (string $name) => ['role' => PartyRole::AdverseParty->value, 'name' => $name], $prospect->opposing_parties ?? []));

            // From an online request whose prescription was screened: track it on the matter.
            if ($prospect->intake_request_id && ($intake = IntakeRequest::find($prospect->intake_request_id))) {
                app(Prescriptions::class)->startFromIntake($intake, $matter, $by);
            }

            $from = $prospect->stage;
            $prospect->forceFill([
                'stage' => 'won',
                'client_id' => $client->id,
                'matter_id' => $matter->id,
                'engagement_signed_on' => $prospect->engagement_signed_on ?? CarbonImmutable::today()->toDateString(),
                'closed_at' => now(),
                'next_step' => null,
                'next_step_on' => null,
            ])->save();
            $this->log($prospect, 'stage', $by, $from, 'won', "Engaged: matter {$matter->reference}");
            AuditLog::record('prospect_converted', $prospect->firm_id, $by, $matter, ['prospect_id' => $prospect->id]);

            return $matter;
        });
    }

    /**
     * Conversion by source and by lawyer, for prospects added in the period,
     * and the open pipeline by stage.
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $prospects = Prospect::query()->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->with('owner:id,name')->get();
        $summarize = function (Collection $group, string $label) {
            $won = $group->where('stage', 'won');
            $lost = $group->where('stage', 'lost');
            $decided = $won->count() + $lost->count();

            return [
                'label' => $label,
                'total' => $group->count(),
                'open' => $group->whereIn('stage', Prospect::OPEN)->count(),
                'won' => $won->count(),
                'lost' => $lost->count(),
                'win_rate' => $decided ? round($won->count() / $decided * 100) : null,
                'won_value_cents' => (int) $won->sum('estimated_value_cents'),
                'avg_days_to_win' => $won->count() ? (int) round($won->avg(fn (Prospect $p) => $p->created_at->diffInDays($p->closed_at ?? now()))) : null,
            ];
        };

        $open = Prospect::query()->whereIn('stage', Prospect::OPEN)->get();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'overall' => $summarize($prospects, 'All'),
            'by_source' => $prospects->groupBy('source')->map(fn ($g, $source) => ['source' => $source, ...$summarize($g, Prospect::SOURCES[$source] ?? $source)])->sortByDesc('total')->values(),
            'by_owner' => $prospects->groupBy(fn (Prospect $p) => $p->owner?->name ?? 'Unassigned')->map(fn ($g, $name) => $summarize($g, $name))->sortByDesc('total')->values(),
            'lost_reasons' => $prospects->where('stage', 'lost')->countBy(fn (Prospect $p) => mb_strtolower(trim((string) $p->lost_reason)) ?: 'not given')->sortDesc()->map(fn ($n, $reason) => ['reason' => $reason, 'count' => $n])->values(),
            'pipeline' => collect(Prospect::OPEN)->map(fn ($stage) => [
                'stage' => $stage,
                'label' => Prospect::STAGES[$stage],
                'count' => $open->where('stage', $stage)->count(),
                'value_cents' => (int) $open->where('stage', $stage)->sum('estimated_value_cents'),
            ])->values(),
        ];
    }

    /**
     * Tell each owner about a follow-up once, on the date they set (or the
     * first run after it); setting a new date brings a new reminder.
     */
    public function sendFollowUps(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;

        Prospect::withoutGlobalScopes()
            ->whereIn('stage', Prospect::OPEN)
            ->whereNotNull('owner_id')
            ->whereDate('next_step_on', '<=', $today->toDateString())
            ->where(fn ($q) => $q->whereNull('reminded_on')->orWhereColumn('reminded_on', '<', 'next_step_on'))
            ->each(function (Prospect $prospect) use ($today, &$sent) {
                $claimed = DB::table('prospects')->where('id', $prospect->id)
                    ->where(fn ($q) => $q->whereNull('reminded_on')->orWhereColumn('reminded_on', '<', 'next_step_on'))
                    ->update(['reminded_on' => $today->toDateString()]);
                if ($claimed !== 1) {
                    return;
                }
                $this->tenant->runAs($prospect->firm_id, function () use ($prospect, &$sent) {
                    $owner = User::where('is_active', true)->find($prospect->owner_id);
                    if ($owner) {
                        $owner->notify(new ProspectFollowUp(Prospect::findOrFail($prospect->id)));
                        $sent++;
                    }
                });
            });

        return $sent;
    }

    /** @return list<int> conflict check ids */
    private function check(User $by, string $name, array $opposing): array
    {
        return collect([$name, ...$opposing])->map(fn ($n) => trim((string) $n))->filter()->unique()
            ->map(fn (string $n) => $this->conflicts->check($by->firm_id, $n, $by)->id)->values()->all();
    }

    private function log(Prospect $prospect, string $type, User $by, ?string $from, ?string $to, ?string $body): ProspectEvent
    {
        return ProspectEvent::create([
            'firm_id' => $prospect->firm_id,
            'prospect_id' => $prospect->id,
            'type' => $type,
            'from_stage' => $from,
            'to_stage' => $to,
            'body' => $body,
            'created_by' => $by->id,
        ]);
    }
}
