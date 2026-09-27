<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Enums\ExpenseCategory;
use App\Domain\Billing\Models\Expense;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Expense::query()
            ->with(['user', 'matter', 'receipt'])
            ->when($request->query('matter_id'), fn ($q, $id) => $q->where('matter_id', $id))
            ->when($request->boolean('unbilled'), fn ($q) => $q->unbilled());

        $total = (int) (clone $query)->sum('amount_cents');

        return ExpenseResource::collection(
            $query->orderByDesc('expense_date')->orderByDesc('id')->paginate($this->perPage($request, 25))
        )->additional(['totals' => ['amount_cents' => $total]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');

        $validated = $request->validate([...$this->rules(), 'matter_id' => ['required', 'integer']]);
        $matter = Matter::findOrFail($validated['matter_id']);
        $this->assertReceiptOnMatter($validated['receipt_file_id'] ?? null, $matter->id);

        $expense = Expense::create([...$validated, 'firm_id' => $matter->firm_id, 'user_id' => $request->user()->id]);

        return (new ExpenseResource($expense->load(['user', 'matter', 'receipt'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Expense $expense): ExpenseResource
    {
        Gate::authorize('modify-expense', $expense);

        $validated = $request->validate(array_map(fn (array $r) => ['sometimes', ...$r], $this->rules()));
        $this->assertReceiptOnMatter($validated['receipt_file_id'] ?? null, $expense->matter_id);
        $expense->update($validated);

        return new ExpenseResource($expense->load(['user', 'matter', 'receipt']));
    }

    public function destroy(Expense $expense): JsonResponse
    {
        Gate::authorize('modify-expense', $expense);

        $expense->delete();

        return response()->json(null, 204);
    }

    private function assertReceiptOnMatter(?int $fileId, int $matterId): void
    {
        if ($fileId !== null && ! MatterFile::whereKey($fileId)->where('matter_id', $matterId)->exists()) {
            throw ValidationException::withMessages(['receipt_file_id' => 'Choose a receipt uploaded to this matter.']);
        }
    }

    private function rules(): array
    {
        return [
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', new Enum(ExpenseCategory::class)],
            'description' => ['required', 'string', 'max:500'],
            // PHP 20,000,000.00 at most, as for trust postings (fits 32-bit integers).
            'amount_cents' => ['required', 'integer', 'min:1', 'max:2000000000'],
            'is_billable' => ['boolean'],
            'receipt_file_id' => ['nullable', 'integer'],
        ];
    }
}
