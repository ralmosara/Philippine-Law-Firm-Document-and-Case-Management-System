@php($money = [\App\Support\Pdf\PdfRenderer::class, 'money'])
@php($signed = fn (int $c) => ($c < 0 ? '(' : '').$money(abs($c)).($c < 0 ? ')' : ''))
@extends('pdf.layout', ['title' => 'Trust reconciliation', 'footer' => 'Trust reconciliation · '.$r->period_end->format('F Y')])

@section('content')
<h1 style="font-size: 15pt">THREE-WAY TRUST RECONCILIATION</h1>
<p>Month ended {{ $r->period_end->format('F j, Y') }} · {{ $r->bank_account }}</p>

<table style="margin-top: 10pt">
    <tr class="group"><td colspan="2">1. Bank</td></tr>
    <tr><td>Balance per bank statement</td><td class="right" style="width: 30%">{{ $signed($r->statement_balance_cents) }}</td></tr>
    @foreach ($r->deposits_in_transit as $d)
        <tr><td style="padding-left: 14pt">Add: deposit in transit · {{ $d['description'] }}</td><td class="right">{{ $money($d['amount_cents']) }}</td></tr>
    @endforeach
    @foreach ($r->outstanding_checks as $c)
        <tr><td style="padding-left: 14pt">Less: outstanding cheque · {{ $c['description'] }}</td><td class="right">({{ $money($c['amount_cents']) }})</td></tr>
    @endforeach
    <tr><td><strong>Adjusted bank balance</strong></td><td class="right"><strong>{{ $signed($r->adjusted_bank_cents) }}</strong></td></tr>

    <tr class="group"><td colspan="2">2. Trust ledger</td></tr>
    <tr><td><strong>Balance per trust ledger</strong> (all postings to {{ $r->period_end->format('M j, Y') }})</td><td class="right"><strong>{{ $signed($r->ledger_cents) }}</strong></td></tr>

    <tr class="group"><td colspan="2">3. Client ledgers</td></tr>
    @foreach ($accounts as $a)
        <tr style="font-size: 9pt"><td style="padding: 3pt 6pt 3pt 14pt">{{ $a['account'] }} · {{ $a['client'] }}@if ($a['matter']) · {{ $a['matter'] }}@endif</td><td class="right" style="padding: 3pt 6pt">{{ $signed($a['balance_cents']) }}</td></tr>
    @endforeach
    <tr><td><strong>Total of client balances</strong></td><td class="right"><strong>{{ $signed($r->client_total_cents) }}</strong></td></tr>
</table>

<table style="margin-top: 12pt">
    <tr><td>Bank less ledger</td><td class="right" style="width: 30%">{{ $signed($r->difference()) }}</td></tr>
    <tr><td>Ledger less client balances</td><td class="right">{{ $signed($r->ledger_cents - $r->client_total_cents) }}</td></tr>
</table>

@if ($r->balances())
    <p style="margin-top: 12pt; padding: 6pt 8pt; background: #e6f4ea; color: #0d652d">The bank, the trust ledger and the client balances agree, and no client balance is below zero.</p>
@else
    <p style="margin-top: 12pt; padding: 6pt 8pt; background: #fce8e6; color: #a50e0e">Does not balance.</p>
    @foreach ($r->exceptions as $e)<p class="muted">• {{ $e }}</p>@endforeach
@endif

@if ($r->notes)<p style="margin-top: 10pt; white-space: pre-wrap"><strong>Notes:</strong> {{ $r->notes }}</p>@endif

<table style="margin-top: 16pt; page-break-inside: avoid">
    <tr>
        <td style="border:0;width:50%">Prepared by<br><strong>{{ $r->preparer?->name }}</strong><br><span class="muted">{{ $r->updated_at?->timezone('Asia/Manila')->format('F j, Y') }}</span></td>
        <td style="border:0">Reviewed and signed off by<br><strong>{{ $r->signer?->name ?? '— not yet signed off —' }}</strong><br><span class="muted">{{ $r->signed_off_at?->timezone('Asia/Manila')->format('F j, Y g:i A') }}</span></td>
    </tr>
</table>
@endsection
