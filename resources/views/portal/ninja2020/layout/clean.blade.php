<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

    <head>
        <script>
            window.gtag = window.gtag || function () {};
            window.trackEvent = window.trackEvent || function () {};
        </script>

        <!-- Title -->
        @if(isset($account) && !$account->isPaid())
            <title>@yield('meta_title', '') - {{ config('ninja.brand_name') }}</title>
        @elseif(auth()->guard('contact')->check())
            <title>@yield('meta_title', '') — {{ auth()->guard('contact')->user()->client->getSetting('name') }}</title>
        @elseif(isset($company) && !is_null($company))
            <title>@yield('meta_title', '') - {{ $company->present()->name() }}</title>
        @else
            <title>@yield('meta_title', '')</title>
        @endif

        @yield('head')
        
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
        @include('portal.ninja2020.components.primary-color')
        @vite('resources/sass/app.scss')
        @if(auth()->guard('contact')->user() && !auth()->guard('contact')->user()->user->account->isPaid())
        {{-- <link href="{{ mix('favicon.png') }}" rel="shortcut icon" type="image/png"> --}}
        @endif

        <link rel="canonical" href="{{ config('ninja.app_url') }}/{{ request()->path() }}"/>

        {{-- Feel free to push anything to header using @push('header') --}}
        @stack('head')

        @livewireStyles

        @if((bool) \App\Utils\Ninja::isSelfHost() && isset($company))
            <style>
                {!! $company->settings->portal_custom_css !!}
            </style>
        @endif
    </head>

    <body data-portal="client" class="antialiased {{ $custom_body_class ?? '' }}">
        @if(session()->has('message'))
            <div class="py-1 text-sm text-center text-white bg-primary disposable-alert">
                {{ session('message') }}
            </div>
        @endif

        <div class="px-4 pt-4">
            @include('portal.ninja2020.components.analytics-consent', ['cleanLayout' => true])
        </div>
        @yield('body')

        @livewireScriptConfig
        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('update-csrf', ({ token }) => {
                    document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
                    document.querySelector('[data-csrf]')?.setAttribute('data-csrf', token);
                });
            });
        </script>

    </body>

    <footer>
        @yield('footer')
        @stack('footer')
    </footer>

</html>
