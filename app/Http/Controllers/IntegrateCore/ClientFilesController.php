<?php

namespace App\Http\Controllers\IntegrateCore;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ShowClientRequest;
use App\Models\Client;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\FileLibrary;
use Illuminate\Support\Facades\Gate;

class ClientFilesController extends Controller
{
    public function show(ShowClientRequest $request, Client $client, ClientFiles $files)
    {
        if (config('integratecore.enabled')) {
            $files->sync($client);
        }
        return response()->json(['data' => $files->status($client)]);
    }

    public function folders(ShowClientRequest $request, Client $client, FileLibrary $library)
    {
        Gate::authorize('edit', $client);
        $assigned = \App\Models\ClientFileFolder::where('client_id', '!=', $client->id)->pluck('folder')->all();
        $folders = array_values(array_map(fn ($entry) => $entry['name'], array_filter($library->entries(), fn ($entry) => $entry['isDir'] && !in_array($entry['name'], $assigned, true))));
        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
        return response()->json(['data' => $folders]);
    }

    public function update(ShowClientRequest $request, Client $client, ClientFiles $files)
    {
        Gate::authorize('edit', $client);
        $validated = $request->validate(['folder' => 'required|string|max:191']);
        return response()->json(['data' => $files->assign($client, $validated['folder'])]);
    }

    public function browse(ShowClientRequest $request, Client $client, \App\Services\IntegrateCore\DocumentLibrary $library)
    {
        $path = $request->validate(['path' => 'nullable|string|max:2048'])['path'] ?? '';
        return response()->json(['data' => $library->listing($client, $path, false)]);
    }

    public function archive(ShowClientRequest $request, Client $client, \App\Services\IntegrateCore\DocumentLibrary $library)
    {
        $path = $request->validate(['path' => 'nullable|string|max:2048'])['path'] ?? '';
        return $library->archive($client, $path, false);
    }

    public function refresh(ShowClientRequest $request, Client $client, ClientFiles $files)
    {
        Gate::authorize('edit', $client);
        $files->migrate($client);
        $files->sync($client, true);
        return response()->json(['data' => $files->status($client)]);
    }
}
