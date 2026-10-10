<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LiveQuery demo</title>
    @vite(['resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <main>{{ $slot }}</main>
    @livewireScripts
</body>
</html>
