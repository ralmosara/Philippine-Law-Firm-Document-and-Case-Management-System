<?php

namespace App\Domain\Prescription;

use App\Casts\DateOnly;
use App\Domain\Matters\Models\Matter;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One cause of action on a matter and when it prescribes. */
class MatterPrescription extends Model
{
    use Auditable, HasTenantScope;

    public const RUNNING = 'running';

    /** The action was filed (or the complaint lodged): prescription no longer runs. */
    public const FILED = 'filed';

    public const INTERRUPTIONS = [
        'filing' => 'Action filed in court',
        'demand' => 'Written extrajudicial demand',
        'acknowledgment' => 'Written acknowledgment by the debtor',
    ];

    protected $fillable = ['firm_id', 'matter_id', 'period_key', 'label', 'years', 'months', 'basis', 'interruptible', 'accrued_on', 'notes', 'created_by'];

    protected $attributes = [
        'status' => self::RUNNING,
        'interruptions' => null,
        'notes' => null,
        'reminded_days' => null,
    ];

    protected function casts(): array
    {
        return [
            'years' => 'integer',
            'months' => 'integer',
            'interruptible' => 'boolean',
            'accrued_on' => DateOnly::class,
            'runs_from' => DateOnly::class,
            'last_day' => DateOnly::class,
            'file_by' => DateOnly::class,
            'filed_on' => DateOnly::class,
            'interruptions' => 'array',
            'reminded_days' => 'integer',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /** Days from today to the nominal last day (negative once past); null when no longer running. */
    public function daysLeft(): ?int
    {
        if ($this->status !== self::RUNNING) {
            return null;
        }
        // Whole calendar days between two dates (PHP's own diff: exact over decades).
        $diff = (new \DateTimeImmutable(today()->toDateString()))->diff(new \DateTimeImmutable($this->last_day->toDateString()));

        return $diff->invert ? -$diff->days : $diff->days;
    }
}
