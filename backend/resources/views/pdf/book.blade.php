@php($money = [\App\Support\Pdf\PdfRenderer::class, 'money'])
@extends('pdf.layout', ['title' => $title, 'footer' => $title.' · '.$month->format('F Y')])

@section('content')
<h1 style="font-size: 14pt">{{ mb_strtoupper($title) }}</h1>
<p>For the month of {{ $month->format('F Y') }}@if ($firm->tin) · TIN {{ $firm->tin }}@endif</p>

<table style="margin-top: 8pt; font-size: 8pt">
    <thead>
        <tr>@foreach ($columns as $key => $label)<th @class(['right' => in_array($key, $money_columns, true)])>{{ $label }}</th>@endforeach</tr>
    </thead>
    @forelse ($rows as $row)
        <tr>
            @foreach ($columns as $key => $label)
                <td @class(['right' => in_array($key, $money_columns, true)]) style="padding: 3pt 4pt">{{ in_array($key, $money_columns, true) ? (($row[$key] ?? 0) ? $money((int) $row[$key]) : '') : ($row[$key] ?? '') }}</td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($columns) }}" class="muted">No entries this month.</td></tr>
    @endforelse
    <tr>
        @foreach ($columns as $key => $label)
            <td @class(['right' => in_array($key, $money_columns, true)]) style="font-weight: bold; border-top: 1pt solid #1f2328; padding: 3pt 4pt">{{ $loop->first ? 'TOTAL' : (isset($totals[$key]) ? $money($totals[$key]) : '') }}</td>
        @endforeach
    </tr>
</table>

<p class="muted" style="margin-top: 10pt">Prepared from {{ $firm->name }}'s records for the accountant. Account titles are suggestions to map to the firm's chart of accounts.@if ($title === 'Client trust funds book') Client funds held in trust are not the firm's income.@endif</p>
@endsection
