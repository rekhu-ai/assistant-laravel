{{-- A Blade layout: the widget for signed-in users, before </body>. It restyles with CSS variables and parts. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>
    <style>
        rekhu-assistant { --c: #0f766e; --rekhu-radius: 20px; --rekhu-width: 440px; }
        rekhu-assistant::part(quick-button) { border-radius: 8px; }
    </style>
</head>
<body>
    {{ $slot }}

    @auth
        <x-rekhu-assistant name="Shop assistant" color="#0f766e" />
    @endauth
</body>
</html>
