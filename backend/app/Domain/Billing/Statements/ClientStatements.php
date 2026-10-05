<?php

namespace App\Domain\Billing\Statements;

use App\Domain\Billing\Statements\Notifications\StatementOfAccountSent;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Emails statements of account: one client on request, or every client
 * once a month on the firm's statement day. Clients who owe nothing and
 * have nothing in trust are skipped, as are those without an email.
 */
class ClientStatements
{
    public function __construct(private readonly StatementOfAccount $statements) {}

    /** Sends one client's statement now. False when there is nothing to send or no email. */
    public function send(Client $client, ?User $by = null): bool
    {
        if (blank($client->email) || $client->anonymized_at) {
            return false;
        }
        $statement = $this->statements->build($client);
        if (! $this->statements->hasContent($statement)) {
            return false;
        }

        $client->notify(new StatementOfAccountSent($client, $statement['as_of']));
        $client->forceFill(['statement_sent_on' => $statement['as_of']->toDateString()])->saveQuietly();
        AuditLog::record('statement_sent', $client->firm_id, $by, $client, ['total_due' => $statement['total_due'], 'trust' => $statement['trust_total']]);

        return true;
    }

    /** Every client of the current firm not yet sent one this month. */
    public function sendAll(?User $by = null, ?CarbonImmutable $today = null): int
    {
        $monthStart = ($today ?? CarbonImmutable::today())->startOfMonth()->toDateString();
        $sent = 0;

        Client::query()
            ->whereNotNull('email')
            ->whereNull('anonymized_at')
            ->where(fn ($q) => $q->whereNull('statement_sent_on')->orWhere('statement_sent_on', '<', $monthStart))
            ->orderBy('id')
            ->each(function (Client $client) use ($by, $monthStart, &$sent) {
                // Claimed first, so two overlapping runs cannot both send.
                $claimed = DB::table('clients')->where('id', $client->id)
                    ->where(fn ($q) => $q->whereNull('statement_sent_on')->orWhere('statement_sent_on', '<', $monthStart))
                    ->update(['statement_sent_on' => $monthStart]) === 1;
                if (! $claimed) {
                    return;
                }
                if ($this->send($client, $by)) {
                    $sent++;
                }
            });

        return $sent;
    }

    /** Daily: firms whose statement day has come and whose clients have not had this month's. */
    public function sendDue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;

        Firm::query()->where('statements_enabled', true)->where('statement_day', '<=', $today->day)->pluck('id')
            ->each(function (int $firmId) use ($today, &$sent) {
                $sent += app(TenantContext::class)->runAs($firmId, fn () => $this->sendAll(null, $today));
            });

        return $sent;
    }
}
