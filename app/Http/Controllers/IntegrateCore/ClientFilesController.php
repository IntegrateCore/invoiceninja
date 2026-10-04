<?php

namespace App\Http\Controllers\IntegrateCore;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ShowClientRequest;
use App\Models\Client;
use App\Models\ClientFileFolder;
use App\Services\IntegrateCore\ClientFiles;
use App\Services\IntegrateCore\FileLibrary;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;

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
        $this->authorizeAdmin($request, $client);
        Gate::authorize('edit', $client);
        $assigned = \App\Models\ClientFileFolder::where('client_id', '!=', $client->id)->pluck('folder')->all();
        $foreignTenants = \Illuminate\Support\Facades\DB::table('client_file_folder_tenants')
            ->where('company_id', '!=', $client->company_id)->pluck('folder')->all();
        $assigned = array_merge($assigned, $foreignTenants);
        $folders = array_values(array_map(fn ($entry) => $entry['name'], array_filter($library->entries(), fn ($entry) => $entry['isDir'] && !in_array($entry['name'], $assigned, true))));
        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
        return response()->json(['data' => $folders]);
    }

    public function update(ShowClientRequest $request, Client $client, ClientFiles $files)
    {
        $this->authorizeAdmin($request, $client);
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
        $this->authorizeAdmin($request, $client);
        Gate::authorize('edit', $client);
        $files->migrate($client);
        $files->sync($client, true);
        return response()->json(['data' => $files->status($client)]);
    }

    public function allFolders(Request $request, FileLibrary $library)
    {
        $this->authorizeAdmin($request);
        if (!config('integratecore.enabled')) {
            return response()->json(['enabled' => false, 'data' => []]);
        }
        $companyId = $request->user()->companyId();
        $mappings = ClientFileFolder::all()->keyBy('folder');
        $tenants = \Illuminate\Support\Facades\DB::table('client_file_folder_tenants')->pluck('company_id', 'folder');
        $clients = Client::withTrashed()->withoutEagerLoads()->where('company_id', $companyId)
            ->whereIn('id', $mappings->where('company_id', $companyId)->pluck('client_id'))
            ->with(['contacts' => fn ($query) => $query->withoutEagerLoads()->select('id', 'client_id', 'email', 'first_name', 'last_name')])
            ->get()->keyBy('id');
        $folders = [];
        foreach ($library->entries() as $entry) {
            if (!$entry['isDir']) {
                continue;
            }
            $mapping = $mappings->get($entry['name']);
            $foreign = ($mapping && (int) $mapping->company_id !== $companyId)
                || ($tenants->has($entry['name']) && (int) $tenants->get($entry['name']) !== $companyId);
            $client = $mapping && !$foreign ? $clients->get($mapping->client_id) : null;
            $folders[] = [
                'folder' => $entry['name'], 'assigned' => (bool) $mapping,
                'client_id' => $client?->hashed_id, 'client_name' => $client?->present()->name(),
                'assigned_to_other_company' => (bool) $foreign,
            ];
        }
        usort($folders, fn ($a, $b) => strnatcasecmp($a['folder'], $b['folder']));
        return response()->json(['enabled' => true, 'data' => $folders]);
    }

    public function updateFolder(Request $request, ClientFiles $files, FileLibrary $library)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate(['folder' => 'required|string|max:191', 'client_id' => 'present|nullable|string|max:64']);
        $client = null;
        if ($validated['client_id'] !== null) {
            $ids = (new \Hashids\Hashids(config('ninja.hash_salt'), 10))->decode($validated['client_id']);
            abort_unless(count($ids) === 1 && $ids[0] > 0, 422, 'Choose a valid client.');
            $client = Client::withoutEagerLoads()->where('company_id', $request->user()->companyId())->findOrFail($ids[0]);
        }
        $files->changeFolder($request->user()->companyId(), $validated['folder'], $client);
        if ($client) {
            $files->sync($client, true);
        }
        return $this->allFolders($request, $library);
    }

    private function authorizeAdmin(Request $request, ?Client $client = null): void
    {
        $user = $request->user();
        abort_unless($user && $user->isAdmin() && (!$client || $user->companyId() === (int) $client->company_id), 403);
    }
}
