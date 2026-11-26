<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

    <head>
        <script>
            window.gtag = window.gtag || function () {};
            window.trackEvent = window.trackEvent || function () {};
        </script>

        <!-- Title -->
        @if(isset($company->account) && !$company->account->isPaid())
            <title>@yield('meta_title', '') - {{ config('ninja.brand_name') }}</title>
        @elseif(isset($company) && !is_null($company))
            <title>@yield('meta_title', '') - {{ $company->present()->name() }}</title>
        @else
            <title>@yield('meta_title', '')</title>
        @endif

        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="@yield('meta_description')"/>

        <!-- CSRF Token -->
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- Scripts -->
        @vite('resources/js/app.js')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --ic-primary: #13999A;
                --ic-secondary: #1C262B;
                --ic-accent: #0F7E7F;
            }

            body {
                font-family: 'Montserrat', 'Segoe UI', Arial, sans-serif;
                color: var(--ic-secondary);
            }
        </style>

        <!-- Styles -->
        @vite('resources/sass/app.scss')

        @if(auth()->guard('vendor')->user() && !auth()->guard('vendor')->user()->user->account->isPaid())
            <link href="{{ asset('favicon.png') }}" rel="shortcut icon" type="image/png">
        @endif

        <link rel="canonical" href="{{ config('ninja.site_url') }}/{{ request()->path() }}"/>

        @if((bool) \App\Utils\Ninja::isSelfHost())
            <style>
                {!! $settings->portal_custom_css !!}
            </style>
        @endif

        @livewireStyles

        {{-- Feel free to push anything to header using @push('header') --}}
        @stack('head')

        @if((isset($company) && $company->account->isPaid() && !empty($settings->portal_custom_head)) || ((bool) \App\Utils\Ninja::isSelfHost() && !empty($settings->portal_custom_head)))
            <div class="py-1 text-sm text-center text-white bg-primary">
                {!! app(\App\Services\ClientPortal\PortalHtmlSanitizer::class)->clean($settings->portal_custom_head) !!}
            </div>
        @endif

    </head>

    @include('portal.ninja2020.components.primary-color')

    <body class="antialiased">
        @if(session()->has('message'))
            <div class="py-1 text-sm text-center text-white bg-primary disposable-alert">
                {{ session('message') }}
            </div>
        @endif

        @component('portal.ninja2020.components.general.sidebar.vendor_main', ['settings' => $settings, 'sidebar' => $sidebar, 'company' => $company])
            @yield('body')
        @endcomponent

        @livewireScriptConfig 

    </body>

    <footer>
        @yield('footer')
        @stack('footer')

        @if($company && $company->account->isPaid() && !empty($settings->portal_custom_footer))
            <div class="py-1 text-sm text-center text-white bg-primary">
                {!! app(\App\Services\ClientPortal\PortalHtmlSanitizer::class)->clean($settings->portal_custom_footer) !!}
            </div>
        @endif
    </footer>

    @if((bool) \App\Utils\Ninja::isSelfHost())
        <script>
            {!! $settings->portal_custom_js !!}
        </script>
    @endif
</html>
