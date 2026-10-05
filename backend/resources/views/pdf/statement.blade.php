@php($money = [\App\Support\Pdf\PdfRenderer::class, 'money'])
@extends('pdf.layout', ['title' => 'Statement of account', 'footer' => 'Statement of account · '.$client->name.' · as of '.$as_of->format('F j, Y')])

@section('content')
<table style="margin-bottom: 14pt">
    <tr>
        <td style="border:0;padding:0;width:50%">
            <div class="muted">CLIENT</div>
            <strong>{{ $client->name }}</strong><br>
            @if ($client->address)<span class="muted">{{ $client->address }}</span><br>@endif
            @if ($client->tin)<span class="muted">TIN {{ $client->tin }}</span>@endif
        </td>
        <td style="border:0;padding:0" class="right">
            <h1 style="font-size: 15pt; white-space: nowrap">STATEMENT OF ACCOUNT</h1>
            As of {{ $as_of->format('F j, Y') }}<br>
            <span class="muted">Payments and trust activity from {{ $from->format('F j, Y') }}</span>
        </td>
    </tr>
</table>

<table style="margin-bottom: 12pt">
    <tr>
        <th>Total due</th>
        @foreach (\App\Domain\Billing\Statements\StatementOfAccount::BUCKETS as $key => $label)
            <th class="right">{{ $label }}</th>
        @endforeach
    </tr>
    <tr>
        <td><strong style="font-size: 12pt">{{ $money($total_due) }}</strong></td>
        @foreach (array_keys(\App\Domain\Billing\Statements\StatementOfAccount::BUCKETS) as $key)
            <td class="right">{{ $aging[$key] ? $money($aging[$key]) : '—' }}</td>
        @endforeach
    </tr>
</table>
<p class="muted" style="margin: -8pt 0 0">Overdue amounts by days past the due date.</p>

<h2 style="font-size: 11pt; margin: 12pt 0 4pt">Open billing statements</h2>
@if ($invoices === [])
    <p class="muted">Nothing is owed. Thank you.</p>
@else
    <table>
        <thead><tr><th>Billing statement</th><th>Issued</th><th>Due</th><th class="right">Amount</th><th class="right">Paid</th><th class="right">Balance</th></tr></thead>
        @foreach ($invoices as $i)
            <tr>
                <td><strong>{{ $i['number'] }}</strong>@if ($i['matter'])<br><span class="muted">{{ $i['matter'] }}</span>@endif</td>
                <td style="white-space: nowrap">{{ $i['issued_at']?->format('M j, Y') }}</td>
                <td style="white-space: nowrap">{{ $i['due_at']?->format('M j, Y') }}@if ($i['days_overdue'] > 0)<br><span class="muted">{{ $i['days_overdue'] }} days overdue</span>@endif</td>
                <td class="right">{{ $money($i['total']) }}</td>
                <td class="right">{{ $i['paid'] ? $money($i['paid']) : '—' }}</td>
                <td class="right" style="white-space: nowrap"><strong>{{ $money($i['balance']) }}</strong></td>
            </tr>
        @endforeach
    </table>
@endif

<h2 style="font-size: 11pt; margin: 14pt 0 4pt">Payments received</h2>
@if ($payments === [])
    <p class="muted">None in this period.</p>
@else
    <table>
        <thead><tr><th>Date</th><th>Billing statement</th><th>Reference</th><th class="right">Received</th><th class="right">Tax withheld</th></tr></thead>
        @foreach ($payments as $p)
            <tr>
                <td style="white-space: nowrap">{{ $p['date']?->format('M j, Y') }}</td>
                <td>{{ $p['invoice'] }}</td>
                <td>{{ $p['reference'] ?? '—' }}</td>
                <td class="right">{{ $money($p['amount']) }}</td>
                <td class="right">{{ $p['withheld'] ? $money($p['withheld']) : '—' }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if ($trust !== [])
    <h2 style="font-size: 11pt; margin: 14pt 0 4pt">Funds held in trust for you: {{ $money($trust_total) }}</h2>
    @foreach ($trust as $a)
        <table style="margin-bottom: 8pt">
            <tr class="group"><td colspan="4">Account {{ $a['account'] }}@if ($a['matter']) · {{ $a['matter'] }}@endif · balance {{ $money($a['balance']) }}</td></tr>
            @forelse ($a['transactions'] as $t)
                <tr>
                    <td style="width: 16%">{{ $t['date']?->timezone('Asia/Manila')->format('M j, Y') }}</td>
                    <td>{{ $t['description'] }}</td>
                    <td class="right" style="width: 18%">{{ $t['type'] === 'deposit' ? '+' : '−' }}{{ $money($t['amount']) }}</td>
                    <td class="right" style="width: 18%">{{ $money($t['balance_after']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No movements in this period.</td></tr>
            @endforelse
        </table>
    @endforeach
@endif

<p class="muted" style="margin-top: 12pt; font-size: 8pt">Please contact us if anything here does not match your records. If you have paid recently, thank you: your payment may not appear yet. This statement summarizes billing statements already sent; it is not an official receipt.</p>
@endsection
