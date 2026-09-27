<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 22mm 20mm 20mm 25mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1f2328; line-height: 1.45; }
    .letterhead { border-bottom: 1.5pt solid #0b57d0; padding-bottom: 6pt; margin-bottom: 14pt; }
    .firm { font-size: 14pt; font-weight: bold; color: #0b57d0; }
    .muted { color: #5f6368; font-size: 8.5pt; }
    h1 { font-size: 15pt; margin: 0 0 4pt; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 8pt; text-transform: uppercase; color: #5f6368; border-bottom: 1pt solid #c4c7c5; padding: 5pt 4pt; }
    td { padding: 5pt 4pt; border-bottom: 0.5pt solid #e1e3e1; vertical-align: top; }
    .right { text-align: right; }
    .group td { background: #f1f3f4; font-size: 8pt; font-weight: bold; text-transform: uppercase; color: #5f6368; }
    .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #80868b; }
    .footer .page:after { content: counter(page) " of " counter(pages); }
    .body-text { font-family: 'DejaVu Serif', serif; font-size: 11pt; line-height: 1.7; white-space: pre-wrap; }
</style>
</head>
<body>
<div class="footer">
    <table><tr>
        <td style="border:0;padding:0">{{ $firm->name }} · {{ $footer ?? $title }}</td>
        <td style="border:0;padding:0" class="right">Page <span class="page"></span></td>
    </tr></table>
</div>

<div class="letterhead">
    <div class="firm">{{ $firm->name }}</div>
    <div class="muted">
        {{ collect([$firm->address, $firm->phone, $firm->email])->filter()->implode(' · ') }}
        @if ($firm->tin)<br>TIN {{ $firm->tin }}@if ($firm->vat_registered) · VAT-registered @endif @endif
    </div>
</div>

@yield('content')
</body>
</html>
