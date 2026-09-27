<?php

namespace App\Domain\Privacy;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Documents\Models\SignatureRequest;
use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Messaging\Models\MessageThread;
use App\Domain\Privacy\Models\DataSubjectRequest;
use App\Domain\Trust\Models\TrustAccount;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The firm's obligations as personal information controller under the Data
 * Privacy Act: answering data subjects, keeping records only as long as
 * needed, and disposing of them securely.
 */
class PrivacyService
{
    public function receiveRequest(Firm $firm, array $data, ?Client $client, string $source): DataSubjectRequest
    {
        return DataSubjectRequest::create([
            'firm_id' => $firm->id,
            'client_id' => $client?->id,
            'requester_name' => $data['requester_name'] ?? $client?->name,
            'requester_email' => $data['requester_email'] ?? $client?->email,
            'type' => $data['type'],
            'details' => $data['details'] ?? null,
            'source' => $source,
            'due_on' => now()->addDays(DataSubjectRequest::RESPONSE_DAYS)->toDateString(),
        ]);
    }

    public function resolve(DataSubjectRequest $request, string $status, string $resolution, User $by): DataSubjectRequest
    {
        if ($request->status !== DataSubjectRequest::OPEN) {
            throw ValidationException::withMessages(['status' => 'This request has already been resolved.']);
        }

        $request->forceFill(['status' => $status, 'resolution' => $resolution, 'handled_by' => $by->id, 'resolved_at' => now()])->save();

        return $request;
    }

    public function acceptNotice(Client $client): void
    {
        $firm = Firm::findOrFail($client->firm_id);
        $client->forceFill(['privacy_notice_version' => $firm->privacy_notice_version, 'privacy_accepted_at' => now()])->save();
    }

    /**
     * Everything the firm holds about a client, as one portable file: the
     * answer to an access or portability request.
     */
    public function export(Client $client): array
    {
        $matters = Matter::withTrashed()->where('client_id', $client->id)->orderBy('id')->get();
        $matterIds = $matters->modelKeys();
        $invoices = Invoice::where('client_id', $client->id)->with('invoicePayments')->orderBy('id')->get();
        $accounts = TrustAccount::where('client_id', $client->id)->with('transactions')->orderBy('id')->get();
        $threads = MessageThread::where('client_id', $client->id)->with('messages')->orderBy('id')->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'controller' => Firm::findOrFail($client->firm_id)->only(['name', 'address', 'email', 'phone', 'dpo_name', 'dpo_email']),
            'profile' => $client->only(['name', 'type', 'email', 'phone', 'tin', 'address', 'portal_enabled', 'privacy_notice_version', 'privacy_accepted_at', 'created_at']),
            'matters' => $matters->map(fn (Matter $m) => [
                'reference' => $m->reference,
                'title' => $m->title,
                'case_type' => $m->case_type,
                'case_number' => $m->case_number,
                'court' => trim("{$m->court} {$m->court_branch}") ?: null,
                'status' => $m->status->label(),
                'opened_at' => $m->opened_at?->toDateString(),
                'closed_at' => $m->closed_at?->toDateString(),
                'other_parties' => $m->parties()->get(['role', 'name'])->map(fn ($p) => ['role' => $p->role->label(), 'name' => $p->name]),
            ])->all(),
            'hearings_and_deadlines' => MatterDeadline::whereIn('matter_id', $matterIds)->orderBy('due_date')
                ->get(['matter_id', 'kind', 'title', 'due_date', 'status'])
                ->map(fn (MatterDeadline $d) => ['matter' => $matters->find($d->matter_id)?->reference, 'kind' => $d->kind->value, 'title' => $d->title, 'date' => $d->due_date->toDateString(), 'status' => $d->status->value])->all(),
            'files' => MatterFile::whereIn('matter_id', $matterIds)->orderBy('id')->get(['matter_id', 'original_name', 'size_bytes', 'shared_with_client', 'created_at'])
                ->map(fn (MatterFile $f) => ['matter' => $matters->find($f->matter_id)?->reference, 'name' => $f->original_name, 'bytes' => $f->size_bytes, 'shared_with_you' => $f->shared_with_client, 'uploaded_at' => $f->created_at?->toIso8601String()])->all(),
            'invoices' => $invoices->map(fn (Invoice $i) => [
                'number' => $i->number,
                'status' => $i->status->value,
                'issued_at' => $i->issued_at?->toDateString(),
                'total' => $this->peso($i->total_cents),
                'balance' => $this->peso($i->balanceDue()),
                'payments' => $i->invoicePayments->whereNull('voided_at')->map(fn ($p) => ['received_on' => $p->received_on->toDateString(), 'method' => $p->method, 'amount' => $this->peso($p->amount_cents), 'tax_withheld' => $this->peso($p->withholding_cents), 'reference' => $p->reference])->values(),
            ])->all(),
            'trust_accounts' => $accounts->map(fn (TrustAccount $a) => [
                'account_number' => $a->account_number,
                'balance' => $this->peso($a->balance_cents),
                'transactions' => $a->transactions->map(fn ($t) => ['date' => $t->created_at?->toDateString(), 'type' => $t->type->value, 'amount' => $this->peso($t->amount_cents), 'description' => $t->description])->values(),
            ])->all(),
            'messages' => $threads->map(fn (MessageThread $t) => [
                'subject' => $t->subject,
                'messages' => $t->messages->map(fn ($m) => ['from' => $m->sender_type === 'client' ? 'you' : 'firm', 'sent_at' => $m->created_at?->toIso8601String(), 'text' => $m->body])->values(),
            ])->all(),
            'signatures' => SignatureRequest::where('client_id', $client->id)->get(['status', 'signed_at', 'created_at'])->toArray(),
            'consultation_requests' => $client->email
                ? IntakeRequest::where('email', $client->email)->get(['case_type', 'description', 'consent_at', 'created_at'])->toArray()
                : [],
            'data_requests' => DataSubjectRequest::where('client_id', $client->id)->get(['type', 'status', 'created_at', 'resolved_at', 'resolution'])->toArray(),
        ];
    }

    /**
     * Erasure, as far as the law allows: the client's contact details and
     * portal access are removed, and the name replaced. Case records, invoices
     * and the trust ledger stay (they are kept for legal claims and tax).
     * Refused while there is open business with the client.
     */
    public function anonymize(Client $client, User $by): Client
    {
        $blockers = array_filter([
            Matter::where('client_id', $client->id)->where('status', '!=', MatterStatus::Closed->value)->exists() ? 'the client has matters that are not closed' : null,
            TrustAccount::where('client_id', $client->id)->where('balance_cents', '>', 0)->exists() ? 'a trust account still holds funds' : null,
            Invoice::where('client_id', $client->id)->whereIn('status', InvoiceStatus::receivableValues())->exists() ? 'an invoice is unpaid' : null,
        ]);
        if ($blockers !== []) {
            throw ValidationException::withMessages(['client' => 'Cannot anonymize yet: '.implode('; ', $blockers).'.']);
        }

        return DB::transaction(function () use ($client, $by) {
            $client->forceFill([
                'name' => "Former client #{$client->id}",
                'email' => null,
                'phone' => null,
                'tin' => null,
                'address' => null,
                'notes' => null,
                'portal_enabled' => false,
                'password' => null,
                'anonymized_at' => now(),
            ])->save();

            // The update above is audited with the old values; keep only that it happened.
            AuditLog::where('subject_type', 'client')->where('subject_id', $client->id)->update(['changes' => null]);
            AuditLog::record('anonymized', $client->firm_id, $by, $client);

            return $client;
        });
    }

    /**
     * Closed matters kept longer than the firm's retention period.
     *
     * @return Collection<int, Matter>
     */
    public function dueForDisposal(Firm $firm): Collection
    {
        return Matter::query()
            ->where('status', MatterStatus::Closed->value)
            ->whereNotNull('closed_at')
            ->whereDate('closed_at', '<=', CarbonImmutable::today()->subYears($firm->retention_years))
            ->with('client:id,name')
            ->withCount('files')
            ->orderBy('closed_at')
            ->get(['id', 'reference', 'title', 'client_id', 'closed_at']);
    }

    /**
     * Secure disposal after the retention period: uploaded files are erased
     * from storage, messages and unsigned drafts deleted, and the matter
     * removed from view. Invoices, the trust ledger, signed documents,
     * status history and the audit log remain.
     *
     * @return array{files: int, threads: int, documents: int}
     */
    public function dispose(Matter $matter, User $by): array
    {
        $firm = Firm::findOrFail($matter->firm_id);
        if ($matter->status !== MatterStatus::Closed || $matter->closed_at === null
            || $matter->closed_at->isAfter(CarbonImmutable::today()->subYears($firm->retention_years))) {
            throw ValidationException::withMessages(['matter' => "Only matters closed more than {$firm->retention_years} years ago can be disposed of."]);
        }
        if (TrustAccount::where('matter_id', $matter->id)->where('balance_cents', '>', 0)->exists()
            || Invoice::where('matter_id', $matter->id)->whereIn('status', InvoiceStatus::receivableValues())->exists()) {
            throw ValidationException::withMessages(['matter' => 'Settle the trust balance and unpaid invoices first.']);
        }

        return DB::transaction(function () use ($matter, $by) {
            // Permanently, including files and drafts deleted earlier (soft-deleted
            // rows still hold content): disposal must not leave copies behind.
            $files = MatterFile::withTrashed()->where('matter_id', $matter->id)->get();
            foreach ($files as $file) {
                Storage::disk(MatterFile::disk())->delete($file->path);
                $file->forceDelete();
            }

            $threads = MessageThread::where('matter_id', $matter->id)->get();
            $threads->each->delete();

            $documents = Document::withTrashed()->where('matter_id', $matter->id)->whereDoesntHave('signatureRequests')->get();
            $documents->each->forceDelete();

            $matter->forceFill(['disposed_at' => now()])->save();
            $matter->delete();
            AuditLog::record('disposed', $matter->firm_id, $by, $matter, ['files' => $files->count(), 'threads' => $threads->count(), 'documents' => $documents->count()]);

            return ['files' => $files->count(), 'threads' => $threads->count(), 'documents' => $documents->count()];
        });
    }

    private function peso(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
