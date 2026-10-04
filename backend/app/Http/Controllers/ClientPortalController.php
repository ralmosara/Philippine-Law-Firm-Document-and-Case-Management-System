<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Payments\OnlinePayments;
use App\Domain\Budgets\MatterBudgets;
use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Documents\Models\SignatureRequest;
use App\Domain\Documents\Search\FileSearch;
use App\Domain\Documents\Services\ElectronicSignatures;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MatterFileController;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The client portal: read-only views of the client's own matters, plus
 * signing documents and paying invoices online. Every query is constrained
 * to the signed-in client in addition to the tenant scope, so a client only
 * ever sees their own matters, and only what the firm has chosen to share.
 */
class ClientPortalController extends Controller
{
    public function getMatters(Request $request): JsonResponse
    {
        $matters = $this->client($request)->matters()
            ->with(['responsibleLawyer:id,name,email', 'deadlines' => $this->upcomingHearings()])
            ->latest('opened_at')
            ->get()
            ->map(fn (Matter $matter) => $this->matterSummary($matter));

        return response()->json(['data' => $matters]);
    }

    public function getMatter(Request $request, int $matter, MatterBudgets $budgets): JsonResponse
    {
        $model = $this->client($request)->matters()
            ->with([
                'responsibleLawyer:id,name,email',
                'deadlines' => $this->upcomingHearings(),
                'statusEvents',
                'documents' => fn ($q) => $q->where('shared_with_client', true)->latest('updated_at'),
                'files' => fn ($q) => $q->select(FileSearch::COLUMNS)->where('shared_with_client', true)->latest(),
                'budget',
            ])
            ->findOrFail($matter);

        // The budget, when the firm chose to share it: the total and how much is used, not the detail.
        $budget = $model->budget?->shared_with_client ? $model->budget->setRelation('matter', $model) : null;
        $usage = $budget ? $budgets->usage($budget) : null;

        return response()->json([
            ...$this->matterSummary($model),
            'court' => $model->court,
            'court_branch' => $model->court_branch,
            'description' => $model->description,
            'budget' => $budget ? [
                'basis' => $budget->basis,
                'total' => $budget->total,
                'used' => $usage['used'],
                'percent' => $usage['percent'],
                'includes_expenses' => $budget->include_expenses,
            ] : null,
            'timeline' => $model->statusEvents->map(fn ($event) => [
                'status' => __($event->to_status->label()),
                'date' => $event->created_at?->toDateString(),
            ]),
            'documents' => $model->documents->map(fn (Document $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'status' => $document->status->value,
                'updated_at' => $document->updated_at?->toDateString(),
            ]),
            'files' => $model->files->map(fn (MatterFile $file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'description' => $file->description,
                'size_bytes' => $file->size_bytes,
                'created_at' => $file->created_at?->toDateString(),
            ]),
        ]);
    }

    public function downloadFile(Request $request, int $file): StreamedResponse
    {
        $model = MatterFile::query()
            ->where('shared_with_client', true)
            ->whereHas('matter', fn ($q) => $q->where('client_id', $this->client($request)->id))
            ->findOrFail($file);

        return MatterFileController::stream($model);
    }

    /** Documents waiting for this client's signature. */
    public function getSignatureRequests(Request $request): JsonResponse
    {
        $requests = SignatureRequest::query()
            ->where('client_id', $this->client($request)->id)
            ->open()
            ->with(['document.matter:id,reference,title', 'requester:id,name'])
            ->latest('id')
            ->get()
            ->map(fn (SignatureRequest $signature) => $this->signatureSummary($signature));

        return response()->json(['data' => $requests]);
    }

    public function getSignatureRequest(Request $request, int $signatureRequest): JsonResponse
    {
        $signature = $this->ownSignatureRequest($request, $signatureRequest)
            ->load(['document.matter:id,reference,title', 'requester:id,name', 'version']);

        return response()->json([
            ...$this->signatureSummary($signature),
            'content' => $signature->version->content,
            'content_sha256' => $signature->content_sha256,
            'signer_name' => $signature->signer_name,
            'responded_at' => $signature->responded_at?->toIso8601String(),
        ]);
    }

    public function signDocument(Request $request, int $signatureRequest, ElectronicSignatures $signatures): JsonResponse
    {
        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'max:255'],
            'method' => ['required', Rule::in(['drawn', 'typed'])],
            // A PNG data URL from the signature pad, at most ~300 KB.
            'signature_image' => ['required_if:method,drawn', 'nullable', 'string', 'max:400000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/]+=*$/'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => __('Please confirm that you agree to sign electronically.'),
            'signature_image.regex' => __('The signature could not be read. Please draw it again.'),
        ]);

        if ($validated['method'] === 'drawn' && ! $this->isPng($validated['signature_image'])) {
            throw ValidationException::withMessages(['signature_image' => __('The signature could not be read. Please draw it again.')]);
        }

        $signature = $signatures->sign(
            $this->ownSignatureRequest($request, $signatureRequest),
            $this->client($request),
            trim($validated['signer_name']),
            $validated['method'],
            $validated['signature_image'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['status' => $signature->status->value, 'signed_at' => $signature->responded_at?->toIso8601String()]);
    }

    public function declineSignature(Request $request, int $signatureRequest, ElectronicSignatures $signatures): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $signature = $signatures->decline(
            $this->ownSignatureRequest($request, $signatureRequest),
            $this->client($request),
            $validated['reason'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['status' => $signature->status->value]);
    }

    /** Start a PayMongo checkout for one of the client's issued invoices. */
    public function checkout(Request $request, int $invoice, OnlinePayments $payments): JsonResponse
    {
        $model = $this->client($request)->invoices()->findOrFail($invoice);
        $payment = $payments->startCheckout($model, rtrim(config('app.frontend_url'), '/').'/portal');

        return response()->json(['checkout_url' => $payment->checkout_url], 201);
    }

    public function getDocument(Request $request, int $document): JsonResponse
    {
        $model = Document::query()
            ->where('shared_with_client', true)
            ->whereHas('matter', fn ($q) => $q->where('client_id', $this->client($request)->id))
            ->with('latestVersion')
            ->findOrFail($document);

        return response()->json([
            'id' => $model->id,
            'title' => $model->title,
            'version' => $model->current_version,
            'content' => $model->latestVersion?->content,
        ]);
    }

    public function getDocumentPdf(Request $request, int $document, PdfRenderer $pdf): Response
    {
        $model = Document::query()
            ->where('shared_with_client', true)
            ->whereHas('matter', fn ($q) => $q->where('client_id', $this->client($request)->id))
            ->findOrFail($document);

        return $pdf->download('pdf.document', DocumentController::pdfData($model), $model->title);
    }

    public function getInvoicePdf(Request $request, int $invoice, PdfRenderer $pdf): Response
    {
        $model = $this->client($request)->invoices()
            ->whereIn('status', [...InvoiceStatus::receivableValues(), InvoiceStatus::Paid->value])
            ->findOrFail($invoice);

        return $pdf->download('pdf.invoice', InvoiceController::pdfData($model), "billing-statement-{$model->number}");
    }

    public function getInvoices(Request $request, OnlinePayments $payments): JsonResponse
    {
        $invoices = $this->client($request)->invoices()
            ->with('matter:id,reference,title')
            ->whereIn('status', [...InvoiceStatus::receivableValues(), InvoiceStatus::Paid->value])
            ->latest('issued_at')
            ->get()
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'is_overdue' => $invoice->isOverdue(),
                'can_pay_online' => $payments->canPay($invoice),
                'total_cents' => $invoice->total_cents,
                'paid_cents' => $invoice->settled_cents,
                'balance_cents' => $invoice->balanceDue(),
                'issued_at' => $invoice->issued_at?->toDateString(),
                'due_at' => $invoice->due_at?->toDateString(),
                'paid_at' => $invoice->paid_at?->toDateString(),
                'matter' => $invoice->matter ? ['reference' => $invoice->matter->reference, 'title' => $invoice->matter->title] : null,
            ]);

        return response()->json([
            'data' => $invoices,
            'outstanding_cents' => (int) $invoices->sum('balance_cents'),
        ]);
    }

    public function getTrustAccounts(Request $request): JsonResponse
    {
        $accounts = $this->client($request)->trustAccounts()
            ->with(['matter:id,reference,title', 'transactions' => fn ($q) => $q->limit(100)])
            ->get()
            ->map(fn (TrustAccount $account) => [
                'id' => $account->id,
                'account_number' => $account->account_number,
                'balance_cents' => $account->balance_cents,
                'status' => $account->status,
                'matter' => $account->matter ? ['reference' => $account->matter->reference, 'title' => $account->matter->title] : null,
                'transactions' => $account->transactions->map(fn ($tx) => [
                    'id' => $tx->id,
                    'type' => $tx->type->value,
                    'amount_cents' => $tx->amount_cents,
                    'balance_after_cents' => $tx->balance_after_cents,
                    'description' => $tx->description,
                    'date' => $tx->created_at?->toDateString(),
                ]),
            ]);

        return response()->json(['data' => $accounts]);
    }

    private function client(Request $request): Client
    {
        return $request->user('client');
    }

    private function ownSignatureRequest(Request $request, int $id): SignatureRequest
    {
        return SignatureRequest::query()->where('client_id', $this->client($request)->id)->findOrFail($id);
    }

    private function signatureSummary(SignatureRequest $signature): array
    {
        return [
            'id' => $signature->id,
            'status' => $signature->isExpired() ? 'expired' : $signature->status->value,
            'message' => $signature->message,
            'expires_at' => $signature->expires_at?->toIso8601String(),
            'requested_at' => $signature->created_at?->toIso8601String(),
            'requested_by' => $signature->requester?->name,
            'document' => [
                'id' => $signature->document->id,
                'title' => $signature->document->title,
            ],
            'matter' => $signature->document->matter
                ? ['reference' => $signature->document->matter->reference, 'title' => $signature->document->matter->title]
                : null,
        ];
    }

    private function isPng(string $dataUrl): bool
    {
        $bytes = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        return $bytes !== false && str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && @getimagesizefromstring($bytes) !== false;
    }

    /** Clients see scheduled hearings, not the firm's internal deadlines. */
    private function upcomingHearings(): \Closure
    {
        return fn ($q) => $q->where('kind', DeadlineKind::Hearing->value)
            ->where('status', DeadlineStatus::Pending->value)
            ->whereDate('due_date', '>=', today())
            ->orderBy('due_date');
    }

    private function matterSummary(Matter $matter): array
    {
        $next = $matter->deadlines->first();

        return [
            'id' => $matter->id,
            'reference' => $matter->reference,
            'title' => $matter->title,
            // Case types are free text; the common ones have a Filipino name.
            'case_type' => __($matter->case_type),
            'case_number' => $matter->case_number,
            'status' => $matter->status->value,
            'status_label' => __($matter->status->label()),
            'progress' => $matter->status->progress(),
            'opened_at' => $matter->opened_at?->toDateString(),
            'lawyer' => $matter->responsibleLawyer ? ['name' => $matter->responsibleLawyer->name, 'email' => $matter->responsibleLawyer->email] : null,
            'next_hearing' => $next ? [
                'title' => $next->title,
                'date' => $next->due_date->toDateString(),
                'time' => $next->due_time ? substr($next->due_time, 0, 5) : null,
                'location' => $next->location,
            ] : null,
        ];
    }
}
