@extends('pdf.layout', ['title' => $document->title, 'footer' => $document->matter?->reference.' · '.$document->title.' · v'.$document->current_version])

@section('content')
<div class="muted" style="margin-bottom: 12pt">
    {{ $document->matter?->reference }} · {{ $document->matter?->title }}
    @if ($document->matter?->case_number) · {{ $document->matter->case_number }} @endif
    · Version {{ $document->current_version }} · {{ ucfirst(str_replace('_', ' ', $document->status->value)) }}
</div>

<div class="body-text">{{ $document->latestVersion?->content }}</div>

@foreach ($signatures as $signature)
    <div style="page-break-before: always"></div>
    <h1>Electronic signature record</h1>
    <p class="muted">Signed electronically in the {{ $firm->name }} client portal. Electronic signatures have the legal effect of handwritten signatures under the E-Commerce Act (RA 8792), Sec. 8.</p>

    <div style="border: 0.75pt solid #c4c7c5; padding: 10pt; text-align: center; margin: 10pt 0 14pt;">
        @if ($signature->signature_method === 'drawn' && $signature->signature_image)
            <img src="{{ $signature->signature_image }}" alt="Signature" style="max-height: 70pt; max-width: 260pt;">
        @else
            <span style="font-family: 'DejaVu Serif', serif; font-style: italic; font-size: 22pt;">{{ $signature->signer_name }}</span>
        @endif
    </div>

    <table>
        <tr><th style="width: 32%">Signed by</th><td>{{ $signature->signer_name }}</td></tr>
        <tr><th>Portal account</th><td>{{ $signature->client?->name }} ({{ $signature->client?->email }})</td></tr>
        <tr><th>Signed at</th><td>{{ $signature->responded_at?->timezone('Asia/Manila')->format('F j, Y g:i:s A') }} (Philippine time)</td></tr>
        <tr><th>Method</th><td>{{ $signature->signature_method === 'drawn' ? 'Drawn signature' : 'Typed name' }}</td></tr>
        <tr><th>IP address</th><td>{{ $signature->signer_ip }}</td></tr>
        <tr><th>Browser</th><td style="font-size: 8pt">{{ $signature->signer_user_agent }}</td></tr>
        <tr><th>Document version</th><td>Version {{ $signature->version?->version_number }}</td></tr>
        <tr><th>Content SHA-256</th><td style="font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt">{{ $signature->content_sha256 }}</td></tr>
        <tr><th>Requested by</th><td>{{ $signature->requester?->name }}, {{ $signature->created_at?->timezone('Asia/Manila')->format('F j, Y g:i A') }}</td></tr>
    </table>
@endforeach
@endsection
