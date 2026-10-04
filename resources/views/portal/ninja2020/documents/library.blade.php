@extends('portal.ninja2020.layout.app')
@section('meta_title', ctrans('texts.documents'))

@section('body')
<div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm overflow-hidden">
    <div class="p-4 sm:p-6 border-b border-gray-200 dark:border-gray-700">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <nav aria-label="{{ ctrans('texts.documents') }}" class="flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('client.documents.index') }}" class="text-primary hover:underline">{{ ctrans('texts.documents') }}</a>
                @php $ancestor = ''; @endphp
                @foreach(array_filter(explode('/', $library['path'])) as $part)
                    @php $ancestor = ltrim($ancestor . '/' . $part, '/'); @endphp
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('client.documents.index', ['path' => $ancestor]) }}" class="text-primary hover:underline">{{ $part }}</a>
                @endforeach
            </nav>
            @if(count($library['entries']))
                <a href="{{ route('client.document_library.archive', ['path' => $library['path']]) }}" class="inline-flex items-center px-4 py-2 bg-primary text-white rounded text-sm">{{ ctrans('texts.download_folder_zip') }}</a>
            @endif
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
                <tr><th class="p-4 font-medium">{{ ctrans('texts.name') }}</th><th class="p-4 font-medium">{{ ctrans('texts.size') }}</th><th class="p-4"><span class="sr-only">{{ ctrans('texts.download') }}</span></th></tr>
            </thead>
            <tbody>
            @forelse($library['entries'] as $entry)
                <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900">
                    <td class="p-4">
                        @if($entry['is_dir'])
                            <a href="{{ route('client.documents.index', ['path' => $entry['path']]) }}" class="flex items-center gap-3 text-primary hover:underline">
                                <svg class="w-5 h-5 flex-shrink-0 text-yellow-600" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 5h6l2 2h10v13H3z"/></svg>{{ $entry['name'] }}
                            </a>
                        @else
                            <a href="{{ route('client.documents.download', ['document' => $entry['id']]) }}" class="text-primary hover:underline break-words">{{ $entry['name'] }}</a>
                        @endif
                    </td>
                    <td class="p-4 whitespace-nowrap">{{ $entry['is_dir'] ? '—' : number_format($entry['size'] / 1024, 1) . ' KB' }}</td>
                    <td class="p-4 text-right whitespace-nowrap">
                        @if(!$entry['is_dir'])
                            <a href="{{ route('client.documents.download', ['document' => $entry['id']]) }}" class="text-primary hover:underline">{{ ctrans('texts.download') }}</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="p-6 text-gray-500">{{ ctrans('texts.no_documents_in_folder') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
