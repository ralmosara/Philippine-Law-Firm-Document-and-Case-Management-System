@php($money = [\App\Support\Pdf\PdfRenderer::class, 'money'])
@extends('pdf.layout', ['title' => 'Billing statement '.$invoice->number, 'footer' => 'Billing statement '.$invoice->number])

@section('content')
<table style="margin-bottom: 14pt">
    <tr>
        <td style="border:0;padding:0;width:55%">
            <div class="muted">BILL TO</div>
            <strong>{{ $invoice->client?->name }}</strong><br>
            @if ($invoice->client?->address)<span class="muted">{{ $invoice->client->address }}</span><br>@endif
            @if ($invoice->client?->tin)<span class="muted">TIN {{ $invoice->client->tin }}</span>@endif
            <div class="muted" style="margin-top:8pt">MATTER</div>
            {{ $invoice->matter?->title }}<br><span class="muted">{{ $invoice->matter?->reference }}</span>
        </td>
        <td style="border:0;padding:0" class="right">
            <h1>BILLING STATEMENT</h1>
            No. {{ $invoice->number }}<br>
            <span class="muted">Issued {{ $invoice->issued_at?->format('F j, Y') ?? '—' }} · Due {{ $invoice->due_at?->format('F j, Y') ?? '—' }}</span><br>
            <span class="muted">Status: {{ ucfirst($invoice->status->value) }}</span>
        </td>
    </tr>
</table>

<table>
    <thead><tr><th style="width:14%">Date</th><th>Description</th><th class="right" style="width:11%">Time</th><th class="right" style="width:15%">Rate</th><th class="right" style="width:16%">Amount</th></tr></thead>
    @foreach (['Professional fees' => $invoice->lines->where('kind', '!=', 'expense'), 'Reimbursable expenses (at cost, not subject to VAT)' => $invoice->lines->where('kind', 'expense')] as $heading => $lines)
        @if ($lines->isNotEmpty())
            <tr class="group"><td colspan="5">{{ $heading }}</td></tr>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->work_date?->format('M j, Y') }}</td>
                    <td>{{ $line->description }}</td>
                    <td class="right">{{ $line->minutes ? intdiv($line->minutes, 60).'h '.($line->minutes % 60).'m' : '—' }}</td>
                    <td class="right">{{ $line->rate_cents ? $money($line->rate_cents).'/hr' : '—' }}</td>
                    <td class="right">{{ $money($line->amount_cents) }}</td>
                </tr>
            @endforeach
        @endif
    @endforeach
</table>

<table style="width: 45%; margin: 12pt 0 0 auto">
    <tr><td>Professional fees</td><td class="right">{{ $money($invoice->subtotal_cents) }}</td></tr>
    <tr><td>VAT (12%)</td><td class="right">{{ $money($invoice->vat_cents) }}</td></tr>
    @if ($invoice->expenses_cents > 0)<tr><td>Reimbursable expenses</td><td class="right">{{ $money($invoice->expenses_cents) }}</td></tr>@endif
    <tr><td style="font-weight:bold;border-bottom:0;border-top:1pt solid #1f2328">Total due</td><td class="right" style="font-weight:bold;border-bottom:0;border-top:1pt solid #1f2328">{{ $money($invoice->total_cents) }}</td></tr>
</table>

@if ($invoice->status->value === 'paid')
    <p style="margin-top: 14pt; padding: 6pt 8pt; background: #e6f4ea; color: #0d652d">Paid {{ $invoice->paid_at?->timezone('Asia/Manila')->format('F j, Y') }}@if ($invoice->payment_reference) · Ref. {{ $invoice->payment_reference }}@endif</p>
@endif

@if ($invoice->notes)<p class="muted" style="margin-top: 14pt; white-space: pre-wrap">{{ $invoice->notes }}</p>@endif

<p class="muted" style="margin-top: 18pt">This billing statement is not an official receipt. An official receipt is issued upon payment.</p>
@endsection
