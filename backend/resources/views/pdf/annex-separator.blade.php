{{-- The page placed before each annex in an e-filing package. --}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: 'DejaVu Serif', serif; color: #111; }
    .page { position: absolute; top: 38%; left: 0; right: 0; text-align: center; }
    .label { font-size: 30pt; font-weight: bold; letter-spacing: 1pt; }
    .description { margin-top: 14pt; font-size: 13pt; padding: 0 60pt; }
    .matter { margin-top: 26pt; font-size: 9pt; color: #555; font-family: 'DejaVu Sans', sans-serif; }
</style>
</head>
<body>
<div class="page">
    <div class="label">{{ $label }}</div>
    @if ($description)<div class="description">{{ $description }}</div>@endif
    <div class="matter">{{ $matter }}</div>
</div>
</body>
</html>
