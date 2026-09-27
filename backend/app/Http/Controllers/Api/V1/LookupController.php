<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Enums\ExpenseCategory;
use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Documents\Models\NotarialEntry;
use App\Domain\Matters\Enums\FeeArrangement;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Enums\PartyRole;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Enumerations the SPA needs for selects and labels, served from the backend
 * so the two can never disagree.
 */
class LookupController extends Controller
{
    public const CASE_TYPES = [
        'Civil', 'Criminal', 'Family', 'Annulment', 'Labor', 'Corporate', 'Estate / Probate',
        'Land Registration', 'Administrative', 'Tax', 'Intellectual Property', 'Special Proceedings',
    ];

    public function __invoke(): JsonResponse
    {
        $options = fn (array $cases) => array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], $cases);

        return response()->json([
            'roles' => $options(Role::cases()),
            'matter_statuses' => $options(MatterStatus::cases()),
            'party_roles' => $options(PartyRole::cases()),
            'deadline_kinds' => array_map(fn (DeadlineKind $k) => ['value' => $k->value, 'label' => ucfirst($k->value)], DeadlineKind::cases()),
            'case_types' => self::CASE_TYPES,
            'notarial_act_types' => array_map(fn (string $t) => ['value' => $t, 'label' => ucwords(str_replace('_', ' ', $t))], NotarialEntry::ACT_TYPES),
            'expense_categories' => $options(ExpenseCategory::cases()),
            'fee_arrangements' => $options(FeeArrangement::cases()),
        ]);
    }
}
