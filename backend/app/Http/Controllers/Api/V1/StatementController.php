<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Statements\ClientStatements;
use App\Domain\Billing\Statements\StatementOfAccount;
use App\Domain\Matters\Models\Client;
use App\Http\Controllers\Controller;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Statements of account: download, email to one client, or to every client now. */
class StatementController extends Controller
{
    public function __construct(private readonly StatementOfAccount $statements, private readonly ClientStatements $sender) {}

    public function show(Client $client): JsonResponse
    {
        Gate::authorize('practice-law');
        $s = $this->statements->build($client);

        return response()->json([
            'total_due' => $s['total_due'],
            'aging' => $s['aging'],
            'open_invoices' => count($s['invoices']),
            'trust_total' => $s['trust_total'],
            'has_content' => $this->statements->hasContent($s),
            'sent_on' => $client->statement_sent_on?->toDateString(),
        ]);
    }

    public function pdf(Client $client, PdfRenderer $pdf): Response
    {
        Gate::authorize('practice-law');

        return $pdf->download('pdf.statement', $this->statements->build($client), 'statement-of-account-'.str($client->name)->slug().'-'.today()->toDateString());
    }

    public function send(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('manage-finances');
        if (blank($client->email)) {
            throw ValidationException::withMessages(['client' => 'Add the client’s email address first.']);
        }
        if (! $this->sender->send($client, $request->user())) {
            throw ValidationException::withMessages(['client' => 'Nothing to send: the client owes nothing and has no funds in trust.']);
        }

        return response()->json(['sent' => true]);
    }

    /** Every client not yet sent this month's statement. */
    public function sendAll(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');

        return response()->json(['sent' => $this->sender->sendAll($request->user())]);
    }
}
