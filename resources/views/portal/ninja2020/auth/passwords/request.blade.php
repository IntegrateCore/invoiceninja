@extends('portal.ninja2020.layout.clean')
@section('meta_title', $title)

@section('body')
    <div class="grid lg:grid-cols-3">
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
            <div class="m-auto w-1/2 md:w-1/3 lg:w-1/4">
                <div>
                    @include('partials.brand-mark', [
                        'class' => 'border-b border-gray-100 pb-4 flex items-center gap-3 justify-center',
                        'imageClass' => 'h-12 w-12'
                    ])
                </div>
                <div class="flex flex-col">
                    <h1 class="text-center text-3xl">{{ ctrans('texts.password_recovery') }}</h1>
                    <p class="text-center mt-1 text-gray-600">{{ ctrans('texts.reset_password_text') }}</p>
                    @if(session('status'))
                        <div class="alert alert-success mt-4">
                            {{ session('status') }}
                        </div>
                    @endif
                    <form action="{{ route($passwordEmailRoute) }}" method="post" class="mt-6">
                        @csrf
                        <div class="flex flex-col">
                            <label for="email" class="input-label">{{ ctrans('texts.email_address') }}</label>
                            @if($company && !is_null($company))
                            <input type="hidden" name="company_key" value="{{$company->company_key}}">
                            @endif
                            @if($is_react)
                            <input type="hidden" name="react" value="true">
                            @endif
                            <input type="email" name="email" id="email"
                                   class="input"
                                   value="{{ request()->query('email') ?? old('email') }}"
                                   autofocus
                                   required>
                            @error('email')
                            <div class="validation validation-fail">
                                {{ $message }}
                            </div>
                            @enderror
                        </div>
                        <div class="mt-5">
                            <button class="button button-primary button-block bg-primary">{{ ctrans('texts.next_step') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    </div>
@endsection
