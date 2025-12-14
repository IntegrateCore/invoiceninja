<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>

    <!-- Source: https://github.com/invoiceninja/invoiceninja -->
    <!-- Error: {{ session('error') }} -->

    @if (config('services.analytics.tracking_id'))
        <script async src="https://www.googletagmanager.com/gtag/js?id=UA-122229484-1"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{{ config('services.analytics.tracking_id') }}', { 'anonymize_ip': true });
            function trackEvent(category, action) {
                ga('send', 'event', category, action, this.src);
            }
        </script>
    @else
        <script>
            function gtag(){}
        </script>
    @endif

    <meta charset="utf-8">
    <title>@yield('meta_title', config('ninja.brand_name')) | {{ config('app.name') }}</title>
    <meta name="description" content="@yield('meta_description')"/>

    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ mix('/css/ninja.min.css') }}">
    <script defer src="/js/lang.js"></script>

    <style>
        body {
            font-family: 'Montserrat', 'Segoe UI', Arial, sans-serif;
            background-color: #F4F7F7;
            color: #1C262B;
        }
    </style>

    @yield('head')
</head>

<body>

@include('header', $header)
@yield('header')

@include('sidebar')
@yield('sidebar')

@yield('body')

@include('dashboard.aside')
@include('footer')
@yield('footer')

{{-- 🔑 REQUIRED: React mount --}}
@include('react.index')

</body>
</html>
