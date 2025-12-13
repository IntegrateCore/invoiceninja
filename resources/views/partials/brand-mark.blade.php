@php
    $containerClasses = $class ?? 'border-b border-gray-100 pb-4 flex items-center gap-3';
    $textClasses = $textClass ?? 'text-gray-800';
    $imageClasses = $imageClass ?? 'h-12 w-12';
    $imageId = $imageId ?? null;
@endphp

<div class="{{ $containerClasses }}">
    <img src="{{ asset('images/integratecore-icon.png') }}" class="{{ $imageClasses }}" @if($imageId) id="{{ $imageId }}" @endif alt="{{ config('ninja.brand_name') }} logo">
    <span class="text-xl font-semibold {{ $textClasses }}" style="font-family: 'Montserrat', 'Segoe UI', Arial, sans-serif;">
        {{ config('ninja.brand_name') }}
    </span>
</div>
