<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    /* Efficient Use of Paper Rule margins: left 1.5 in, top 1.2 in, right and bottom 1 in.
       Pleadings are laid out as fixed-width text (the caption is aligned with spaces), so a
       monospaced face keeps the layout; the size fits 78 characters to the line. */
    @page { margin: 86pt 72pt 72pt 108pt; }
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: {{ $fontSize }}pt; line-height: 1.5; color: #000; }
    .text { white-space: pre-wrap; }
</style>
</head>
<body>
<div class="text">{{ $content }}</div>
</body>
</html>
