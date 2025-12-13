@extends('portal.ninja2020.layout.clean')
@section('meta_title', ctrans('texts.confirmation'))

@section('body')

    <div class="flex h-screen">
        <div class="m-auto md:w-1/3 lg:w-1/2">
            <div class="flex flex-col items-center">

                @if($account && !$account->isPaid())
                    <div>
                        <img src="{{ asset('images/invoiceninja-black-logo-2.png') }}"
                             @include('partials.brand-mark', [
                                 'class' => 'border-b border-gray-100 pb-4 flex items-center gap-3 justify-center',
                                 'imageClass' => 'h-12 w-12'
                             ])
                    </div>
                @elseif($company)
                    <div>
                        <img src="{{ $company->present()->logo()  }}"
                             class="mx-auto border-b border-gray-100 h-18 pb-4" alt="{{ $company->present()->name() }} logo">
                    </div>
                @else
                    <div>
                        <img src="{{ asset('images/invoiceninja-black-logo-2.png') }}"
                             @include('partials.brand-mark', [
                                 'class' => 'mx-auto border-b border-gray-100 pb-4 flex items-center gap-3 justify-center',
                                 'imageClass' => 'h-12 w-12'
                             ])
                    </div>
                @endif

                <h1 class="text-center text-3xl mt-10">{{ $title }}</h1>
                <p class="text-center opacity-75">{{ $notification }}</p>
            </div>
        </div>
    </div>

@stop

@push('footer')

@endpush
