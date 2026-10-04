@extends('pdf.layout', ['title' => $document->title.' — comparison', 'footer' => 'Comparison · '.$document->title])

@section('content')
<style>
    .legend span { display: inline-block; margin-right: 14pt; }
    ins { color: #0b57d0; text-decoration: underline; }
    del { color: #b3261e; text-decoration: line-through; }
    del + ins { margin-left: 3pt; }
    .redline { font-family: 'DejaVu Serif', serif; font-size: 10.5pt; line-height: 1.65; white-space: pre-wrap; }
    .sides td { font-size: 9pt; }
</style>

<h1>{{ $document->title }}</h1>
@if ($document->matter)
    <p class="muted" style="margin-top: 0">{{ $document->matter->reference }} · {{ $document->matter->title }}</p>
@endif

<table class="sides" style="margin: 8pt 0 10pt">
    <tr><th style="width: 22%">Original</th><td><strong>{{ $result['base']['label'] }}</strong> — {{ $result['base']['detail'] }}</td></tr>
    <tr><th>Compared with</th><td><strong>{{ $result['other']['label'] }}</strong> — {{ $result['other']['detail'] }}</td></tr>
    <tr><th>Changes</th><td>{{ number_format($result['inserted_words']) }} {{ $result['inserted_words'] === 1 ? 'word' : 'words' }} added, {{ number_format($result['deleted_words']) }} {{ $result['deleted_words'] === 1 ? 'word' : 'words' }} removed</td></tr>
    <tr><th>Prepared</th><td>{{ now()->timezone('Asia/Manila')->format('F j, Y g:i A') }} by {{ $by }}</td></tr>
</table>

<p class="legend muted"><span><ins>Added text</ins></span><span><del>Removed text</del></span>Unchanged text is in black.</p>

@if ($result['inserted_words'] === 0 && $result['deleted_words'] === 0)
    <p><strong>No differences in wording.</strong> Spacing and line breaks may differ.</p>
@endif

{{-- One expression: whitespace between Blade directives would end up in the text. --}}
<div class="redline">{!! collect($result['segments'])->map(fn ($s) => match ($s['type']) { 'insert' => '<ins>'.e($s['text']).'</ins>', 'delete' => '<del>'.e($s['text']).'</del>', default => e($s['text']) })->implode('') !!}</div>
@endsection
