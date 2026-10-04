<?php

namespace App\Domain\Prescription;

use App\Domain\Deadlines\Services\DeadlineCalculator;
use App\Domain\Matters\Models\Matter;
use App\Domain\Prescription\Notifications\PrescriptionApproaching;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Prescription of causes of action. A period counted in years or months
 * ends on the same date that many calendar years or months later (a "year"
 * is 12 calendar months: Administrative Code of 1987, Book I, Sec. 31; CIR
 * v. Primetown, 2007), excluding the day it began; a last day on a
 * Saturday, Sunday or holiday moves to the next working day (Rule 22,
 * Sec. 1). Reminders count down to the nominal last day, the safer of the
 * two, from six months out.
 */
class Prescriptions
{
    /** Days before the last day at which the lawyer is reminded; 0 is the day itself, -1 once it has passed. */
    public const STAGES = [180, 90, 30, 14, 7, 3, 1, 0, -1];

    /** From this stage on, the managing partners are told too. */
    private const ESCALATE_AT = 30;

    public function __construct(private readonly DeadlineCalculator $calculator) {}

    /**
     * @return array{last_day: CarbonImmutable, file_by: CarbonImmutable, adjustments: list<array{date: string, reason: string}>}
     */
    public function compute(CarbonInterface $from, int $years, int $months): array
    {
        $nominal = CarbonImmutable::parse($from->format('Y-m-d'))->addYearsNoOverflow($years)->addMonthsNoOverflow($months);
        $rolled = $this->calculator->rollForward($nominal);

        return ['last_day' => $rolled->nominalDate, 'file_by' => $rolled->dueDate, 'adjustments' => $rolled->adjustments];
    }

    /** @param array{period_key?: ?string, label?: ?string, years?: ?int, months?: ?int, basis?: ?string, accrued_on: string, notes?: ?string} $input */
    public function save(Matter $matter, array $input, User $by, ?MatterPrescription $prescription = null): MatterPrescription
    {
        $period = filled($input['period_key'] ?? null) ? PrescriptionPeriods::find($input['period_key']) : null;
        $prescription ??= new MatterPrescription(['firm_id' => $matter->firm_id, 'matter_id' => $matter->id, 'created_by' => $by->id]);

        $prescription->fill([
            'period_key' => $period ? $input['period_key'] : null,
            'label' => $period['label'] ?? trim((string) $input['label']),
            'years' => $period['years'] ?? (int) ($input['years'] ?? 0),
            'months' => $period['months'] ?? (int) ($input['months'] ?? 0),
            'basis' => $period['basis'] ?? ($input['basis'] ?? null),
            'interruptible' => $period['interruptible'] ?? true,
            'accrued_on' => $input['accrued_on'],
            'notes' => $input['notes'] ?? null,
        ]);

        if ($prescription->years === 0 && $prescription->months === 0) {
            throw ValidationException::withMessages(['years' => 'Enter the period in years or months.']);
        }

        // The latest interruption still restarts the period; otherwise it runs from accrual.
        $restart = collect($prescription->interruptions ?? [])->max('date');
        $this->recompute($prescription, $restart && $restart > $prescription->accrued_on->toDateString() ? CarbonImmutable::parse($restart) : $prescription->accrued_on);
        $prescription->save();

        return $prescription;
    }

    /** Civil Code, Art. 1155: the period starts anew from the interruption. */
    public function interrupt(MatterPrescription $prescription, string $date, string $kind, ?string $note): MatterPrescription
    {
        if (! $prescription->interruptible) {
            throw ValidationException::withMessages(['kind' => 'This period is not interrupted that way. For crimes, record the filing of the complaint as "filed".']);
        }
        if ($date < $prescription->accrued_on->toDateString()) {
            throw ValidationException::withMessages(['date' => 'The interruption cannot be before the cause of action arose.']);
        }

        $prescription->interruptions = [...($prescription->interruptions ?? []), ['date' => $date, 'kind' => $kind, 'note' => $note]];
        $latest = collect($prescription->interruptions)->max('date');
        $this->recompute($prescription, CarbonImmutable::parse($latest));
        $prescription->save();

        return $prescription;
    }

    public function markFiled(MatterPrescription $prescription, string $date): MatterPrescription
    {
        $prescription->forceFill(['status' => MatterPrescription::FILED, 'filed_on' => $date])->save();

        return $prescription;
    }

    public function reopen(MatterPrescription $prescription): MatterPrescription
    {
        $prescription->forceFill(['status' => MatterPrescription::RUNNING, 'filed_on' => null, 'reminded_days' => null])->save();

        return $prescription;
    }

    /** Daily: remind as each stage is reached; returns how many reminders went out. */
    public function sendReminders(): int
    {
        $sent = 0;
        MatterPrescription::query()
            ->where('status', MatterPrescription::RUNNING)
            ->whereDate('last_day', '<=', today()->addDays(self::STAGES[0]))
            ->with('matter:id,firm_id,reference,title,responsible_lawyer_id')
            ->each(function (MatterPrescription $p) use (&$sent) {
                $days = $p->daysLeft();
                $stage = collect(self::STAGES)->filter(fn (int $s) => $days <= $s)->last();
                if ($stage === null || ($p->reminded_days !== null && $p->reminded_days <= $stage)) {
                    return;
                }

                $recipients = User::query()
                    ->where('firm_id', $p->firm_id)
                    ->where('is_active', true)
                    ->where(fn ($q) => $q->where('id', $p->matter->responsible_lawyer_id)
                        ->when($stage <= self::ESCALATE_AT, fn ($q) => $q->orWhere('role', Role::ManagingPartner->value)))
                    ->get();
                Notification::send($recipients, new PrescriptionApproaching($p, $days));
                $p->forceFill(['reminded_days' => $stage])->saveQuietly();
                $sent++;
            });

        return $sent;
    }

    private function recompute(MatterPrescription $prescription, CarbonInterface $from): void
    {
        $result = $this->compute($from, $prescription->years, $prescription->months);
        $changed = $prescription->last_day?->toDateString() !== $result['last_day']->toDateString();

        $prescription->forceFill([
            'runs_from' => $from->format('Y-m-d'),
            'last_day' => $result['last_day']->toDateString(),
            'file_by' => $result['file_by']->toDateString(),
        ]);
        // A new last day starts the reminders over.
        if ($changed) {
            $prescription->reminded_days = null;
        }
    }
}
