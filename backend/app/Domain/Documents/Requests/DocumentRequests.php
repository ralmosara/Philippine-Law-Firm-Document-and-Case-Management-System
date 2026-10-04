<?php

namespace App\Domain\Documents\Requests;

use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Documents\Requests\Notifications\DocumentReturned;
use App\Domain\Documents\Requests\Notifications\DocumentsReminder;
use App\Domain\Documents\Requests\Notifications\DocumentsRequested;
use App\Domain\Documents\Requests\Notifications\DocumentUploaded;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Collecting documents from clients: the firm lists what it needs, the
 * client uploads each item in the portal, and a lawyer accepts it or sends
 * it back with a reason. Reminders go out before and after the due date.
 */
class DocumentRequests
{
    public const REMIND_DAYS_BEFORE = 3;

    public function __construct(
        private readonly StoreMatterFile $files,
        private readonly TenantContext $tenant,
    ) {}

    /** @param  list<array{label: string, description?: ?string, required?: bool}>  $items */
    public function create(Matter $matter, User $by, string $title, ?string $message, ?string $dueOn, array $items): DocumentRequest
    {
        $client = Client::findOrFail($matter->client_id);
        if (! $client->portal_enabled || ! $client->email) {
            throw ValidationException::withMessages(['client' => 'The client needs portal access to upload documents. Turn it on from the client page first.']);
        }

        $request = DB::transaction(function () use ($matter, $by, $title, $message, $dueOn, $items, $client) {
            $request = DocumentRequest::create([
                'firm_id' => $matter->firm_id,
                'matter_id' => $matter->id,
                'client_id' => $client->id,
                'title' => $title,
                'message' => $message,
                'due_on' => $dueOn,
                'created_by' => $by->id,
            ]);
            foreach (array_values($items) as $i => $item) {
                $request->items()->create([
                    'firm_id' => $matter->firm_id,
                    'label' => $item['label'],
                    'description' => $item['description'] ?? null,
                    'required' => $item['required'] ?? true,
                    'position' => $i,
                ]);
            }

            return $request;
        });

        $client->notify(new DocumentsRequested($request));

        return $request->load('items');
    }

    /** The client's upload for one item: scanned, filed with the matter, shared back with them. */
    public function upload(DocumentRequestItem $item, UploadedFile $file, Client $client): DocumentRequestItem
    {
        $request = DocumentRequest::findOrFail($item->document_request_id);
        if ((int) $request->client_id !== (int) $client->id) {
            abort(404);
        }
        if ($request->status !== DocumentRequest::OPEN || ! $item->awaitingClient()) {
            throw ValidationException::withMessages(['file' => __('This item is not waiting for a document.')]);
        }

        $matter = Matter::findOrFail($request->matter_id);
        $stored = $this->files->execute($matter, $file, $client, "Requested: {$item->label}", sharedWithClient: true);

        $item->forceFill([
            'status' => DocumentRequestItem::UPLOADED,
            'matter_file_id' => $stored->id,
            'uploaded_at' => now(),
            'review_note' => null,
        ])->save();

        $reviewer = User::where('is_active', true)->find($request->created_by ?? $matter->responsible_lawyer_id)
            ?? User::where('is_active', true)->find($matter->responsible_lawyer_id);
        $reviewer?->notify(new DocumentUploaded($item, $request, $client));

        return $item;
    }

    public function review(DocumentRequestItem $item, bool $accept, ?string $note, User $by): DocumentRequestItem
    {
        if ($item->status !== DocumentRequestItem::UPLOADED) {
            throw ValidationException::withMessages(['item' => 'Only an uploaded document can be reviewed.']);
        }
        if (! $accept && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Tell the client what is wrong, so they can send the right document.']);
        }

        return DB::transaction(function () use ($item, $accept, $note, $by) {
            $item->forceFill([
                'status' => $accept ? DocumentRequestItem::ACCEPTED : DocumentRequestItem::REJECTED,
                'review_note' => $note,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
            ])->save();

            $request = DocumentRequest::whereKey($item->document_request_id)->lockForUpdate()->firstOrFail();
            if ($accept) {
                $this->completeIfDone($request);
            } else {
                Client::find($request->client_id)?->notify(new DocumentReturned($item, $request));
            }

            return $item;
        });
    }

    public function cancel(DocumentRequest $request): DocumentRequest
    {
        if ($request->status !== DocumentRequest::OPEN) {
            throw ValidationException::withMessages(['request' => 'This request is already closed.']);
        }
        $request->forceFill(['status' => DocumentRequest::CANCELLED])->save();

        return $request;
    }

    private function completeIfDone(DocumentRequest $request): void
    {
        $outstanding = DocumentRequestItem::where('document_request_id', $request->id)
            ->where('required', true)->where('status', '!=', DocumentRequestItem::ACCEPTED)->exists();

        if (! $outstanding && $request->status === DocumentRequest::OPEN) {
            $request->forceFill(['status' => DocumentRequest::COMPLETED, 'completed_at' => now()])->save();
        }
    }

    /**
     * A reminder a few days before the due date and one once it has passed,
     * for requests still missing required documents.
     */
    public function sendReminders(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;

        DocumentRequest::withoutGlobalScopes()
            ->where('status', DocumentRequest::OPEN)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $today->addDays(self::REMIND_DAYS_BEFORE)->toDateString())
            ->orderBy('id')
            ->each(function (DocumentRequest $request) use ($today, &$sent) {
                $stage = CarbonImmutable::instance($request->due_on)->lt($today) ? 'overdue' : 'due_soon';
                if ($request->last_reminder === $stage || ($request->last_reminder === 'overdue')) {
                    return;
                }

                $this->tenant->runAs($request->firm_id, function () use ($request, $stage, &$sent) {
                    $missing = DocumentRequestItem::where('document_request_id', $request->id)->where('required', true)
                        ->whereIn('status', [DocumentRequestItem::PENDING, DocumentRequestItem::REJECTED])->pluck('label')->all();
                    if ($missing === []) {
                        return;
                    }

                    // Claim the stage first, so overlapping runs send it once.
                    $claimed = DocumentRequest::whereKey($request->id)->where(fn ($q) => $q->whereNull('last_reminder')->orWhere('last_reminder', '!=', $stage))
                        ->update(['last_reminder' => $stage]);
                    if ($claimed === 1) {
                        Client::find($request->client_id)?->notify(new DocumentsReminder($request, $missing, $stage));
                        $sent++;
                    }
                });
            });

        return $sent;
    }
}
