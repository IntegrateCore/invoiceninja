@extends('themes.ninja2020.clean')
@section('meta_title', ctrans('texts.confirmation'))

@section('body')
    <div class="flex h-screen">
        <div class="m-auto md:w-1/3 lg:w-1/5">
            <div class="flex flex-col items-center">
                @include('partials.brand-mark', [
                    'class' => 'border-b border-gray-100 pb-4 flex items-center gap-3 justify-center',
                    'imageClass' => 'h-12 w-12'
                ])
                <h1 class="text-center text-3xl mt-10">{{ ctrans('texts.confirmation') }}</h1>
                <p class="text-center opacity-75">{{ $message }}</p>
                <a class="button button-primary text-blue-600 text-center mt-8" href="{{ $redirect_url }}">{{ ctrans('texts.return_to_login') }}</a>
            </div>
        </div>
    </div>
@endsection
