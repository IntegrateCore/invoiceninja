@extends('portal.ninja2020.layout.clean')
@section('meta_title', ctrans('texts.login'))

@section('head')
    @component('portal.ninja2020.components.test')
        <input type="hidden" id="test_email" value="{{ config('ninja.testvars.username') }}">
        <input type="hidden" id="test_password" value="{{ config('ninja.testvars.password') }}">
    @endcomponent

    <style>
        .ic-login-shell {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(19, 153, 154, 0.22), transparent 28%),
                radial-gradient(circle at 85% 18%, rgba(255, 255, 255, 0.12), transparent 22%),
                linear-gradient(160deg, #071417 0%, #0f2024 44%, #1c262b 100%);
        }

        .ic-login-card {
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(18px);
        }

        .ic-login-panel {
            background:
                linear-gradient(180deg, rgba(7, 20, 23, 0.18) 0%, rgba(7, 20, 23, 0.84) 100%),
                url('{{ asset('images/client-portal-new-image.jpg') }}') center/cover no-repeat;
        }

        .ic-login-pill {
            border: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(255, 255, 255, 0.08);
        }

        .ic-login-input {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(148, 163, 184, 0.25);
            color: #102025;
        }

        .ic-login-input:focus {
            border-color: #13999a;
            box-shadow: 0 0 0 0.2rem rgba(19, 153, 154, 0.18);
        }

        .ic-login-button {
            background: linear-gradient(135deg, #13999a 0%, #0f7e7f 100%);
            box-shadow: 0 12px 30px rgba(15, 126, 127, 0.32);
        }

        .ic-login-button:hover {
            filter: brightness(1.05);
        }

        .ic-faint-grid {
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
            background-size: 32px 32px;
        }
    </style>
@endsection

@section('body')
    <div class="ic-login-shell flex items-center justify-center px-4 py-8">
        <div class="ic-login-card relative w-full max-w-6xl overflow-hidden rounded-[2rem] bg-white/6 text-white">
            <div class="absolute inset-0 ic-faint-grid opacity-40"></div>

            <div class="relative grid lg:grid-cols-2">
                <div class="ic-login-panel hidden lg:flex min-h-[32rem] flex-col justify-between p-10 text-white">
                    <div class="flex items-center gap-4">
                        <img src="{{ asset('images/integratecore-icon.png') }}"
                             alt="{{ config('ninja.brand_name') }} logo"
                             class="h-14 w-14 rounded-2xl bg-white/10 p-2 ring-1 ring-white/15">
                        <div>
                            <div class="text-2xl font-semibold tracking-tight">{{ config('ninja.brand_name') }}</div>
                            <div class="text-xs uppercase tracking-[0.35em] text-white/65">Client Portal</div>
                        </div>
                    </div>

                    <div class="max-w-md">
                        <p class="text-sm uppercase tracking-[0.3em] text-white/65">Fast access</p>
                        <h1 class="mt-4 text-5xl font-semibold leading-tight tracking-tight">
                            Manage invoices, payments, and documents in one place.
                        </h1>
                        <p class="mt-5 max-w-lg text-base leading-7 text-white/80">
                            A clean client portal experience built for IntegrateCore standards, with the important stuff up front and the noise stripped away.
                        </p>
                    </div>

                    <div class="grid grid-cols-3 gap-3 text-sm">
                        <div class="rounded-2xl ic-login-pill px-4 py-3">
                            <div class="font-semibold">Invoices</div>
                            <div class="text-white/65">Status at a glance</div>
                        </div>
                        <div class="rounded-2xl ic-login-pill px-4 py-3">
                            <div class="font-semibold">Payments</div>
                            <div class="text-white/65">Quickly settle balances</div>
                        </div>
                        <div class="rounded-2xl ic-login-pill px-4 py-3">
                            <div class="font-semibold">Docs</div>
                            <div class="text-white/65">Everything in one place</div>
                        </div>
                    </div>
                </div>

                <div class="bg-[#f7fafb] px-6 py-10 sm:px-10 lg:px-12 lg:py-14 text-[#102025]">
                    <div class="mx-auto flex w-full max-w-lg flex-col">
                        <div class="lg:hidden flex items-center gap-3">
                            <img src="{{ asset('images/integratecore-icon.png') }}"
                                 alt="{{ config('ninja.brand_name') }} logo"
                                 class="h-12 w-12 rounded-xl bg-[#0f2024] p-2">
                            <div>
                                <div class="text-xl font-semibold">{{ config('ninja.brand_name') }}</div>
                                <div class="text-xs uppercase tracking-[0.3em] text-slate-500">Client Portal</div>
                            </div>
                        </div>

                        <div class="mt-8 lg:mt-0">
                            <p class="text-sm font-semibold uppercase tracking-[0.3em] text-[#13999a]">Welcome back</p>
                            <h2 class="mt-3 text-3xl font-semibold tracking-tight text-slate-900">{{ ctrans('texts.login') }}</h2>
                            <p class="mt-3 max-w-md text-sm leading-6 text-slate-600">
                                Sign in to review invoices, download documents, and keep payments moving without the clutter.
                            </p>
                        </div>

                        @if (Session::has('error'))
                            <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                                {!! Session::get('error') !!}
                            </div>
                        @endif

                        <form action="{{ route('client.login.submit') }}" method="post" class="mt-8 space-y-5">
                            @csrf

                            <div>
                                <label for="email" class="mb-2 block text-sm font-medium text-slate-700">{{ ctrans('texts.email_address') }}</label>
                                <input type="email"
                                       name="email"
                                       id="email"
                                       value="{{ old('email') }}"
                                       autofocus
                                       autocomplete="email"
                                       class="ic-login-input w-full rounded-2xl px-4 py-3 outline-none transition"
                                       placeholder="{{ ctrans('texts.email_address') }}">
                                @error('email')
                                    <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <div class="mb-2 flex items-center justify-between gap-4">
                                    <label for="password" class="block text-sm font-medium text-slate-700">{{ ctrans('texts.password') }}</label>
                                    <a class="text-sm font-medium text-[#0f7e7f] hover:text-[#0b6667] transition"
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
                                       class="ic-login-input w-full rounded-2xl px-4 py-3 outline-none transition"
                                       placeholder="{{ ctrans('texts.password') }}">
                                @error('password')
                                    <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                                @enderror
                            </div>

                            <button id="loginBtn"
                                    class="ic-login-button inline-flex w-full items-center justify-center rounded-2xl px-5 py-3.5 text-base font-semibold text-white transition">
                                {{ trans('texts.login') }}
                            </button>
                        </form>

                        @if(!is_null($company) && $company->client_can_register)
                            <div class="mt-6 rounded-2xl border border-slate-200 bg-white px-4 py-4 text-center shadow-sm">
                                <div class="text-sm text-slate-600">{{ ctrans('texts.not_a_member_yet') }}</div>
                                <a class="mt-3 inline-flex items-center justify-center rounded-full bg-[#0f7e7f] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#0b6667]"
                                   href="{{ route('client.register') }}">
                                    {{ ctrans('texts.register_label') }}
                                </a>
                            </div>
                        @endif

                        @if(!is_null($company) && !empty($company->present()->website()))
                            <div class="mt-6 text-center">
                                <a class="text-sm font-medium text-slate-500 hover:text-slate-800 transition"
                                   href="{{ $company->present()->website() }}">
                                    {{ ctrans('texts.back_to', ['url' => parse_url($company->present()->website())['host'] ?? $company->present()->website() ]) }}
                                </a>
                            </div>
                        @endif

                        <div class="mt-8 flex flex-wrap items-center justify-center gap-3 text-xs uppercase tracking-[0.25em] text-slate-400">
                            <span>Secure access</span>
                            <span>•</span>
                            <span>IntegrateCore branded</span>
                            <span>•</span>
                            <span>Client portal</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
