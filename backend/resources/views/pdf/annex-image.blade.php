{{-- An image annex (a photo or scan saved as JPG or PNG), one per page. --}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 36pt; }
    body { margin: 0; text-align: center; }
    img { max-width: 100%; max-height: 100%; }
</style>
</head>
<body><img src="{{ $src }}" alt=""></body>
</html>
