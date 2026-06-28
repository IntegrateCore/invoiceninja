@extends('portal.ninja2020.layout.clean')
@section('meta_title', ctrans('texts.login'))

@section('head')

@component('portal.ninja2020.components.test')
<input type="hidden" id="test_email" value="{{ config('ninja.testvars.username') }}">
<input type="hidden" id="test_password" value="{{ config('ninja.testvars.password') }}">
@endcomponent

@endsection

@section('body')
    <div class="grid lg:grid-cols-3 mx-6 md:mx-0">
        <div class="hidden lg:flex col-span-1 h-screen items-center justify-center relative overflow-hidden"
             style="background: linear-gradient(160deg, #13999A 0%, #0F7E7F 55%, #1C262B 100%);">
            <div class="absolute inset-0 opacity-10"
                 style="background-image: radial-gradient(circle at top right, rgba(255,255,255,.35), transparent 28%), radial-gradient(circle at bottom left, rgba(255,255,255,.2), transparent 24%);">
            </div>
            <div class="relative text-center px-10 text-white">
                <img src="{{ asset('images/integratecore-icon.png') }}"
                     class="mx-auto w-24 h-24 mb-6"
                     alt="{{ config('ninja.brand_name') }} logo">
                <div class="text-4xl font-semibold tracking-tight">{{ config('ninja.brand_name') }}</div>
                <div class="mt-3 text-sm uppercase tracking-widest text-white/70">Client portal</div>
            </div>
        </div>

        <div class="col-span-3 lg:col-span-2 h-screen flex">
            <div class="m-auto md:w-1/2 lg:w-1/4">
                <div>
                    @include('partials.brand-mark', [
                        'class' => 'border-b border-gray-100 pb-4 flex items-center gap-3 justify-center',
                        'imageClass' => 'h-12 w-12',
                        'imageId' => 'company_logo'
                    ])
                </div>

                <div class="flex flex-col">
                    <h1 class="text-center text-3xl">{{ ctrans('texts.client_portal') }}</h1>
                    <form action="{{ route('client.login') }}" method="post" class="mt-6">
                        @csrf
                        <div class="flex flex-col">
                            <label for="email" class="input-label">{{ ctrans('texts.email_address') }}</label>
                            <input type="email" name="email" id="email"
                                   class="input"
                                   value="{{ old('email') }}"
                                   autofocus>
                            @error('email')
                            <div class="validation validation-fail">
                                {{ $message }}
                            </div>
                            @enderror
                        </div>
                        <div class="flex flex-col mt-4">
                            <div class="flex justify-between items-center">
                                <label for="password" class="input-label">{{ ctrans('texts.password') }}</label>
                                <a class="text-xs text-gray-600 hover:text-gray-800 ease-in duration-100"
                                   href="{{ route('client.password.request') }}">{{ trans('texts.forgot_password') }}</a>
                            </div>
                            @if(isset($company) && !is_null($company))
                            <input type="hidden" name="company_key" value="{{$company->company_key}}">
                            @endif
                            <input type="password" name="password" id="password"
                                   class="input"
                                   autofocus>
                            @error('password')
                            <div class="validation validation-fail">
                                {{ $message }}
                            </div>
                            @enderror
                        </div>
                        <div class="mt-5">
                            <button id="loginBtn" class="button button-primary button-block bg-primary">
                                {{ trans('texts.login') }}
                            </button>
                        </div>
                    </form>

                    @if(!is_null($company) && $company->client_can_register)
                        <div class="mt-5 text-center">
                            <a class="button-link text-sm" href="{{ route('client.register') }}">{{ ctrans('texts.register_label') }}</a>
                        </div>
                    @endif

                    @if(!is_null($company) && !empty($company->present()->website()))
                        <div class="mt-5 text-center">
                            <a class="button-link text-sm" href="{{ $company->present()->website() }}">
                                {{ ctrans('texts.back_to', ['url' => parse_url($company->present()->website())['host'] ?? $company->present()->website() ]) }}
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
