<?php

namespace App\Domain\Matters\Models;

use App\Casts\DateOnly;
use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Budgets\MatterBudget;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Feedback\MatterFeedback;
use App\Domain\Matters\Enums\FeeArrangement;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Trust\Models\TrustAccount;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Database\Factories\MatterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Matter extends Model
{
    /** @use HasFactory<MatterFactory> */
    use Auditable, HasFactory, HasTenantScope, SoftDeletes;

    /**
     * Status is deliberately not fillable: it only changes through
     * TransitionMatterStatus, which validates the transition and records it.
     */
    protected $fillable = [
        'firm_id',
        'client_id',
        'responsible_lawyer_id',
        'reference',
        'title',
        'case_type',
        'case_number',
        'court',
        'court_branch',
        'judge',
        'description',
        'opened_at',
        'fee_arrangement',
        'fixed_fee_cents',
        'acceptance_fee_cents',
        'appearance_fee_cents',
        'contingency_basis_points',
        'retainer_auto_bill',
        'retainer_billing_day',
        'retainer_auto_issue',
        'client_role',
        'nature_of_action',
    ];

    protected $attributes = [
        'status' => 'intake',
    ];

    protected function casts(): array
    {
        return [
            'status' => MatterStatus::class,
            'opened_at' => DateOnly::class,
            'closed_at' => DateOnly::class,
            'fee_arrangement' => FeeArrangement::class,
            'client_id' => 'integer',
            'responsible_lawyer_id' => 'integer',
            'fixed_fee_cents' => 'integer',
            'acceptance_fee_cents' => 'integer',
            'appearance_fee_cents' => 'integer',
            'contingency_basis_points' => 'integer',
            'retainer_auto_bill' => 'boolean',
            'retainer_billing_day' => 'integer',
            'retainer_auto_issue' => 'boolean',
            'retainer_billed_through' => DateOnly::class,
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Matter $matter) {
            $matter->opened_at ??= now();
            $matter->reference ??= static::nextReference($matter->firm_id, $matter->opened_at->year);
        });
    }

    /**
     * Next sequential firm reference for the year, e.g. M-2026-0042. The
     * (firm_id, reference) unique index is the final guard against races.
     */
    public static function nextReference(int $firmId, int $year): string
    {
        $prefix = "M-{$year}-";

        $last = static::withoutGlobalScopes()
            ->where('firm_id', $firmId)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', MatterStatus::Closed->value);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function responsibleLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_lawyer_id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(MatterParty::class);
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(MatterFeedback::class);
    }

    public function budget(): HasOne
    {
        return $this->hasOne(MatterBudget::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(MatterStatusEvent::class)->latest('id');
    }

    public function deadlines(): HasMany
    {
        return $this->hasMany(MatterDeadline::class);
    }

    /** The earliest open deadline. */
    public function nextDeadline(): HasOne
    {
        return $this->hasOne(MatterDeadline::class)->ofMany(
            ['due_date' => 'min', 'id' => 'min'],
            fn (Builder $query) => $query->where('status', 'pending'),
        );
    }

    public function files(): HasMany
    {
        return $this->hasMany(MatterFile::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function trustAccounts(): HasMany
    {
        return $this->hasMany(TrustAccount::class);
    }

    protected static function newFactory(): MatterFactory
    {
        return MatterFactory::new();
    }
}
