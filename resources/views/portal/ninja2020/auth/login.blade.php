@extends('portal.ninja2020.layout.clean')
@section('meta_title', ctrans('texts.login'))

@section('head')
    @component('portal.ninja2020.components.test')
        <input type="hidden" id="test_email" value="{{ config('ninja.testvars.username') }}">
        <input type="hidden" id="test_password" value="{{ config('ninja.testvars.password') }}">
    @endcomponent

    <style>
        .ic-portal-shell {
            min-height: 100vh;
            background: #f5f7fb;
        }

        .ic-portal-visual {
            position: relative;
            min-height: 32rem;
            background:
                linear-gradient(180deg, rgba(10, 22, 27, 0.14) 0%, rgba(10, 22, 27, 0.42) 100%),
                url('{{ asset('images/client-portal-waves.jpg') }}') center center / cover no-repeat;
        }

        .ic-portal-visual::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(19, 153, 154, 0.12), rgba(28, 38, 43, 0.08));
        }

        .ic-portal-brand {
            position: relative;
            z-index: 1;
        }

        .ic-portal-logo {
            height: 3rem;
            width: 3rem;
            border-radius: 0.9rem;
            padding: 0.45rem;
            box-shadow: 0 18px 40px rgba(12, 20, 26, 0.08);
        }

        .ic-portal-panel {
            background: #ffffff;
        }

        .ic-portal-label {
            color: #4b5563;
            font-size: 0.92rem;
            font-weight: 600;
        }

        .ic-portal-input {
            width: 100%;
            border-radius: 0.9rem;
            border: 1px solid #d9e1ec;
            background: #ffffff;
            color: #102025;
            padding: 0.95rem 1rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .ic-portal-input:focus {
            border-color: #13999a;
            box-shadow: 0 0 0 0.2rem rgba(19, 153, 154, 0.14);
            outline: none;
        }

        .ic-portal-button {
            border-radius: 0.9rem;
            background: #13999a;
            color: #ffffff;
            box-shadow: 0 14px 28px rgba(19, 153, 154, 0.18);
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .ic-portal-button:hover {
            background: #0f7e7f;
            transform: translateY(-1px);
        }

        @media (max-width: 1023px) {
            .ic-portal-visual {
                min-height: 18rem;
            }
        }

        @media (min-width: 1024px) {
            .ic-portal-shell {
                display: grid;
                grid-template-columns: 42% 58%;
            }

            .ic-portal-visual {
                display: block;
                min-height: 100vh;
            }
        }
    </style>
@endsection

@section('body')
    <div class="ic-portal-shell">
        <div class="ic-portal-visual hidden lg:block"></div>

        <div class="ic-portal-panel flex min-h-screen items-center justify-center px-6 py-10 sm:px-10 lg:px-16">
            <div class="w-full max-w-md">
                <div class="ic-portal-brand mb-10 flex items-center gap-4">
                    <img src="{{ asset('images/integratecore-icon.png') }}"
                         alt="{{ config('ninja.brand_name') }} logo"
                         class="ic-portal-logo">
                    <div>
                        <div class="text-2xl font-bold tracking-tight text-[#102025]">{{ config('ninja.brand_name') }}</div>
                    </div>
                </div>

                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-[#102025]">Client Portal</h1>
                </div>

                @if (Session::has('error'))
                    <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        {!! Session::get('error') !!}
                    </div>
                @endif

                <form action="{{ route('client.login.submit') }}" method="post" class="mt-8 space-y-5">
                    @csrf

                    <div>
                        <label for="email" class="ic-portal-label mb-2 block">{{ ctrans('texts.email_address') }}</label>
                        <input type="email"
                               name="email"
                               id="email"
                               value="{{ old('email') }}"
                               autofocus
                               autocomplete="email"
                               class="ic-portal-input"
                               placeholder="{{ ctrans('texts.email_address') }}">
                        @error('email')
                            <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-4">
                            <label for="password" class="ic-portal-label block">{{ ctrans('texts.password') }}</label>
                            <a class="text-sm font-medium text-[#13999a] transition hover:text-[#0f7e7f]"
                               href="{{ route('client.password.request') }}">
                                {{ trans('texts.forgot_password') }}
                            </a>
                        </div>

                        @if(isset($company) && !is_null($company))
                            <input type="hidden" name="company_key" value="{{ $company->company_key }}">
                        @endif

                        <input type="password"
                               name="password"
                               id="password"
                               autocomplete="current-password"
                               class="ic-portal-input"
                               placeholder="{{ ctrans('texts.password') }}">
                        @error('password')
                            <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <button id="loginBtn"
                            class="ic-portal-button inline-flex w-full items-center justify-center px-5 py-3 text-base font-semibold">
                        {{ trans('texts.login') }}
                    </button>
                </form>

                @if(!is_null($company) && $company->client_can_register)
                    <div class="mt-6 text-center">
                        <a class="text-sm font-medium text-[#13999a] transition hover:text-[#0f7e7f]"
                           href="{{ route('client.register') }}">
                            {{ ctrans('texts.register_label') }}
                        </a>
                    </div>
                @endif

                @if(!is_null($company) && !empty($company->present()->website()))
                    <div class="mt-8 text-center">
                        <a class="text-sm font-medium text-slate-500 transition hover:text-slate-800"
                           href="{{ $company->present()->website() }}">
                            {{ ctrans('texts.back_to', ['url' => parse_url($company->present()->website())['host'] ?? $company->present()->website() ]) }}
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
