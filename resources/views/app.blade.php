<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Shopify Commerce Hub') }}</title>

        @vite('resources/js/app.ts')
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
