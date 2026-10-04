@extends('portal.ninja2020.layout.app')
@section('meta_title', ctrans('texts.documents'))

@push('head')
<style>
    .ic-document-library {
        background: #fff;
        color: #253236;
        border: 1px solid #e2e6e9;
        border-radius: 6px;
        overflow: hidden;
        font-family: 'Segoe UI', system-ui, sans-serif;
    }
    .ic-document-library a { color: #0f7e7f; }
    .ic-document-library a:hover { text-decoration: underline; }
    .ic-document-library a:focus-visible { outline: 2px solid #0f7e7f; outline-offset: 3px; }
    .ic-document-library .ic-library-toolbar { border-bottom: 1px solid #e2e6e9; }
    .ic-document-library .ic-library-zip {
        background: #0f7e7f;
        color: #fff;
        border-radius: 4px;
        font-weight: 600;
        white-space: nowrap;
    }
    .ic-document-library .ic-library-zip:hover { background: #0b6768; text-decoration: none; }
    .ic-document-library table { table-layout: fixed; }
    .ic-document-library thead { background: #f7f8fa; color: #4b5563; border-bottom: 1px solid #e2e6e9; }
    .ic-document-library tbody tr { border-bottom: 1px solid #edf0f2; }
    .ic-document-library tbody tr:last-child { border-bottom: 0; }
    .ic-document-library tbody tr:hover { background: #f7fafb; }
    .ic-document-library th, .ic-document-library td { padding: 14px 16px; vertical-align: middle; }
    .ic-document-library .ic-library-name { overflow-wrap: anywhere; }
    .ic-document-library .ic-library-size { width: 100px; text-align: right; white-space: nowrap; color: #4b5563; }
    .ic-document-library .ic-library-action { width: 110px; text-align: right; white-space: nowrap; }
    .ic-document-library .ic-library-empty { padding: 32px 16px; color: #4b5563; }
    @media (max-width: 640px) {
        .ic-document-library th, .ic-document-library td { padding: 12px 10px; }
        .ic-document-library .ic-library-size { width: 72px; }
        .ic-document-library .ic-library-action { width: 88px; }
    }
</style>
@endpush

@section('body')
<div class="ic-document-library">
    <div class="ic-library-toolbar p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <nav aria-label="{{ ctrans('texts.documents') }}" class="flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('client.documents.index') }}" @if($library['path'] === '') aria-current="page" @endif>{{ ctrans('texts.documents') }}</a>
                @php $ancestor = ''; @endphp
                @foreach(array_filter(explode('/', $library['path'])) as $part)
                    @php $ancestor = ltrim($ancestor . '/' . $part, '/'); @endphp
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('client.documents.index', ['path' => $ancestor]) }}" @if($ancestor === $library['path']) aria-current="page" @endif>{{ $part }}</a>
                @endforeach
            </nav>
            @if(count($library['entries']))
                <a href="{{ route('client.document_library.archive', ['path' => $library['path']]) }}" class="ic-library-zip inline-flex items-center px-4 py-2 text-sm">{{ ctrans('texts.download_folder_zip') }}</a>
            @endif
        </div>
    </div>
    <table class="w-full text-sm text-left">
        <thead>
            <tr><th class="font-medium">{{ ctrans('texts.name') }}</th><th class="ic-library-size font-medium">{{ ctrans('texts.size') }}</th><th class="ic-library-action"><span class="sr-only">{{ ctrans('texts.download') }}</span></th></tr>
        </thead>
        <tbody>
        @forelse($library['entries'] as $entry)
            <tr>
                <td class="ic-library-name">
                    @if($entry['is_dir'])
                        <a href="{{ route('client.documents.index', ['path' => $entry['path']]) }}" class="flex items-center gap-3">
                            <svg class="w-5 h-5 flex-shrink-0 text-yellow-600" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 5h6l2 2h10v13H3z"/></svg>{{ $entry['name'] }}
                        </a>
                    @else
                        <a href="{{ route('client.documents.preview', ['document' => $entry['id'], 'path' => $library['path']]) }}">{{ $entry['name'] }}</a>
                    @endif
                </td>
                <td class="ic-library-size">{{ $entry['is_dir'] ? '—' : number_format($entry['size'] / 1024, 1) . ' KB' }}</td>
                <td class="ic-library-action">
                    @if(!$entry['is_dir'])
                        <a href="{{ route('client.documents.download', ['document' => $entry['id']]) }}">{{ ctrans('texts.download') }}</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="3" class="ic-library-empty">{{ ctrans('texts.no_documents_in_folder') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
