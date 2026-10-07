@php($money = [\App\Support\Pdf\PdfRenderer::class, 'money'])
@php($acts = ['acknowledgment' => 'Acknowledgment', 'jurat' => 'Jurat', 'oath' => 'Oath or affirmation', 'copy_certification' => 'Copy certification', 'signature_witnessing' => 'Signature witnessing'])
@extends('pdf.layout', ['title' => 'Notarial report', 'footer' => 'Notarial report · '.$notary->name.' · '.$month->format('F Y')])

@section('content')
<p style="margin: 0">The Executive Judge, through the Clerk of Court{{ $notary->notarial_commission_place ? ', '.$notary->notarial_commission_place : '' }}</p>
<h1 style="font-size: 14pt; margin-top: 8pt">REPORT OF NOTARIAL ACTS FOR {{ mb_strtoupper($month->format('F Y')) }}</h1>
<table style="width: auto; margin: 6pt 0 10pt">
    <tr><td style="border:0;padding:1pt 12pt 1pt 0" class="muted">Notary public</td><td style="border:0;padding:1pt 0"><strong>{{ mb_strtoupper($notary->name) }}</strong></td></tr>
    <tr><td style="border:0;padding:1pt 12pt 1pt 0" class="muted">Commission</td><td style="border:0;padding:1pt 0">{{ $notary->notarial_commission_number ?? '______' }}{{ $notary->notarial_commission_place ? ', '.$notary->notarial_commission_place : '' }}{{ $notary->notarial_commission_expires_on ? ', until '.$notary->notarial_commission_expires_on->format('F j, Y') : '' }}</td></tr>
    <tr><td style="border:0;padding:1pt 12pt 1pt 0" class="muted">Roll of Attorneys No.</td><td style="border:0;padding:1pt 0">{{ $notary->roll_number ?? '______' }}</td></tr>
    <tr><td style="border:0;padding:1pt 12pt 1pt 0" class="muted">Office</td><td style="border:0;padding:1pt 0">{{ $firm->name }}{{ $firm->address ? ', '.$firm->address : '' }}</td></tr>
</table>

@if ($entries->isEmpty())
    <p style="padding: 8pt; border: 1pt solid #c4c7cc">No notarial act was performed during {{ $month->format('F Y') }}.</p>
@else
    <table style="font-size: 8pt">
        <thead><tr><th>Doc. No.</th><th>Page</th><th>Book</th><th>Series</th><th>Date and time</th><th>Notarial act</th><th>Document</th><th>Principal(s)</th><th>Competent evidence of identity</th><th class="right">Fee</th></tr></thead>
        @foreach ($entries as $e)
            <tr>
                <td style="padding: 3pt 4pt">{{ $e->doc_number }}</td>
                <td style="padding: 3pt 4pt">{{ $e->page_number }}</td>
                <td style="padding: 3pt 4pt">{{ $e->book_number }}</td>
                <td style="padding: 3pt 4pt">{{ $e->series_year }}</td>
                <td style="padding: 3pt 4pt; white-space: nowrap">{{ $e->notarized_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
                <td style="padding: 3pt 4pt">{{ $acts[$e->act_type] ?? $e->act_type }}</td>
                <td style="padding: 3pt 4pt">{{ $e->document_title }}</td>
                <td style="padding: 3pt 4pt">{{ $e->principal_name }}</td>
                <td style="padding: 3pt 4pt">{{ $e->competent_evidence }}</td>
                <td class="right" style="padding: 3pt 4pt">{{ $e->fee_cents ? $money($e->fee_cents) : '—' }}</td>
            </tr>
        @endforeach
    </table>
    <p class="muted" style="margin-top: 4pt">{{ $entries->count() }} {{ $entries->count() === 1 ? 'entry' : 'entries' }}.</p>
@endif

<p style="margin-top: 14pt">I hereby certify that the foregoing is a true and correct copy of the entries in my notarial register for the month of {{ $month->format('F Y') }}{{ $entries->isEmpty() ? ', during which I performed no notarial act' : '' }}.</p>

<table style="margin-top: 30pt; width: 45%; margin-left: auto; page-break-inside: avoid">
    <tr><td style="border:0; border-top: 1pt solid #1f2328; text-align: center; padding-top: 4pt"><strong>{{ mb_strtoupper($notary->name) }}</strong><br><span class="muted">Notary Public</span></td></tr>
</table>
@endsection
