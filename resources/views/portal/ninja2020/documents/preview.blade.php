@extends('portal.ninja2020.layout.app')
@section('meta_title', ctrans('texts.preview') . ' - ' . $document->name)

@push('head')
<style>
    .ic-document-preview { background: #fff; color: #253236; border: 1px solid #e2e6e9; border-radius: 6px; overflow: hidden; font-family: 'Segoe UI', system-ui, sans-serif; }
    .ic-document-preview a { color: #0f7e7f; }
    .ic-document-preview a:hover { text-decoration: underline; }
    .ic-document-preview a:focus-visible, .ic-document-preview pre:focus-visible { outline: 2px solid #0f7e7f; outline-offset: 3px; }
    .ic-preview-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; padding: 20px; border-bottom: 1px solid #e2e6e9; }
    .ic-preview-toolbar h1 { margin: 8px 0 0; font-size: 20px; font-weight: 600; overflow-wrap: anywhere; }
    .ic-preview-content { padding: 20px; }
    .ic-document-preview .ic-preview-download { display: inline-flex; padding: 8px 16px; background: #0f7e7f; color: #fff; border-radius: 4px; font-weight: 600; }
    .ic-document-preview .ic-preview-download:hover { background: #0b6768; text-decoration: none; }
    .ic-preview-image { display: block; max-width: 100%; max-height: 75vh; margin: 0 auto; object-fit: contain; }
    .ic-preview-pdf { display: block; width: 100%; height: 75vh; min-height: 400px; border: 1px solid #e2e6e9; }
    .ic-document-preview pre { margin: 0; padding: 16px; background: #f7f8fa; color: #253236; border: 1px solid #e2e6e9; border-radius: 4px; overflow: auto; max-height: 75vh; font-size: 13px; line-height: 1.6; }
    .ic-document-preview code { color: inherit; background: transparent; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
    .ic-preview-notice { margin: 0 0 16px; color: #4b5563; }
    @media (max-width: 640px) { .ic-preview-toolbar, .ic-preview-content { padding: 14px; } .ic-preview-pdf { min-height: 320px; } }
</style>
@endpush

@section('body')
<section class="ic-document-preview" aria-labelledby="ic-preview-title">
    <div class="ic-preview-toolbar">
        <div>
            <nav aria-label="{{ ctrans('texts.documents') }}" class="flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('client.documents.index') }}">{{ ctrans('texts.documents') }}</a>
                @php $ancestor = ''; @endphp
                @foreach(array_filter(explode('/', $library_path ?? '')) as $part)
                    @php $ancestor = ltrim($ancestor . '/' . $part, '/'); @endphp
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('client.documents.index', ['path' => $ancestor]) }}">{{ $part }}</a>
                @endforeach
            </nav>
            <h1 id="ic-preview-title">{{ $document->name }}</h1>
        </div>
        <a href="{{ route('client.documents.download', ['document' => $document->hashed_id]) }}" class="ic-preview-download" download>{{ ctrans('texts.download') }}</a>
    </div>
    <div class="ic-preview-content" aria-label="{{ ctrans('texts.preview') }}">
        @if($preview['notice'] === 'media_size')
            <p class="ic-preview-notice" role="status">{{ ctrans('texts.document_preview_too_large') }}</p>
        @elseif($preview['notice'] === 'text_truncated')
            <p class="ic-preview-notice" role="status">{{ ctrans('texts.document_preview_truncated') }}</p>
        @elseif($preview['notice'] === 'binary')
            <p class="ic-preview-notice" role="status">{{ ctrans('texts.document_preview_not_text') }}</p>
        @elseif($preview['notice'] === 'unsupported')
            <p class="ic-preview-notice" role="status">{{ ctrans('texts.document_preview_unavailable') }}</p>
        @endif

        @if($preview['kind'] === 'image')
            <img class="ic-preview-image" src="{{ route('client.documents.preview_content', ['document' => $document->hashed_id]) }}" alt="{{ $document->name }}">
        @elseif($preview['kind'] === 'pdf')
            <iframe class="ic-preview-pdf" src="{{ route('client.documents.preview_content', ['document' => $document->hashed_id]) }}" title="{{ $document->name }}"></iframe>
            <p class="ic-preview-notice"><a href="{{ route('client.documents.preview_content', ['document' => $document->hashed_id]) }}" target="_blank" rel="noopener">{{ ctrans('texts.open_in_new_tab') }}</a></p>
        @elseif($preview['kind'] === 'text')
            <pre tabindex="0" aria-label="{{ $document->name }}"><code>{{ $preview['text'] }}</code></pre>
        @endif
    </div>
</section>
@endsection
