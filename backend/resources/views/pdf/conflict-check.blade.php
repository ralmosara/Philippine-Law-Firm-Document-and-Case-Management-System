@extends('pdf.layout', ['title' => 'Conflict check', 'footer' => 'Conflict check #'.$check->id.' · '.$check->created_at->timezone('Asia/Manila')->format('F j, Y')])

@section('content')
<h1>Conflict-of-interest check</h1>
<p class="muted">Code of Professional Responsibility and Accountability, Canon III: a lawyer shall not represent conflicting interests except by written informed consent of all concerned.</p>

<table style="margin: 10pt 0 14pt">
    <tr><th style="width: 30%">Name searched</th><td><strong>{{ $check->search_term }}</strong></td></tr>
    <tr><th>Searched on</th><td>{{ $check->created_at->timezone('Asia/Manila')->format('F j, Y g:i A') }} (Philippine time)</td></tr>
    <tr><th>Searched by</th><td>{{ $check->requester?->name ?? 'Automatic check (online intake)' }}</td></tr>
    <tr><th>What was searched</th><td>All clients of {{ $firm->name }}, former clients and their other names, and every party to every matter, open or closed.</td></tr>
    <tr><th>Result</th><td>{{ $check->match_count === 0 ? 'No possible conflict found' : $check->match_count.' possible '.($check->match_count === 1 ? 'match' : 'matches') }}</td></tr>
    <tr><th>Status</th><td>{{ ucfirst($check->status->value) }}</td></tr>
</table>

@if ($check->match_count > 0)
    <h2 style="font-size: 12pt; margin: 14pt 0 4pt">Possible matches</h2>
    <table>
        <thead><tr><th>Name on file</th><th>Relationship</th><th>Matter</th><th>Why it matched</th></tr></thead>
        <tbody>
        @foreach ($check->matches as $match)
            <tr>
                <td>{{ $match['name'] }}</td>
                <td>{{ $match['relationship'] }}@if ($match['is_adverse']) <strong>(adverse)</strong>@endif</td>
                <td>{{ trim(($match['matter_reference'] ?? '').' '.($match['matter_title'] ?? '')) ?: '—' }}</td>
                <td>{{ $match['reason'] ?? 'Name match' }}@isset($match['score']) ({{ $match['score'] }}%)@endisset</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@if ($check->resolved_at)
    <h2 style="font-size: 12pt; margin: 14pt 0 4pt">Resolution</h2>
    <table>
        <tr><th style="width: 30%">Decision</th><td>{{ $check->status->value === 'waived' ? 'Waived: engagement may proceed' : 'Declined: engagement refused' }}</td></tr>
        <tr><th>By</th><td>{{ $check->resolver?->name }}, {{ $check->resolved_at->timezone('Asia/Manila')->format('F j, Y g:i A') }}</td></tr>
        <tr><th>Reasons</th><td style="white-space: pre-wrap">{{ $check->resolution_notes }}</td></tr>
    </table>
@endif

<p class="muted" style="margin-top: 18pt">Matching ignores word order, punctuation, titles and company forms, joins name particles (de la, delos) and allows small misspellings. A match is a lead to review, not a finding that a conflict exists.</p>
@endsection
