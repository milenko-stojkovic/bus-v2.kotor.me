@php
    use Illuminate\Support\Str;
    $tz = config('app.timezone');
@endphp

<x-admin-panel-layout page-title="Sistemska arhiva - neuspjeli fajlovi" nav-active="archive-failed">
    <div class="space-y-6">
        @if (session('status'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <div>
            <h1 class="text-xl font-semibold text-gray-900">Sistemska arhiva — neuspjeli fajlovi</h1>
            <p class="mt-2 text-sm text-gray-600">Redovi u <code class="text-xs bg-red-50 px-1 rounded">external_file_archives</code> sa statusom <strong>failed</strong>. Ponovni pokušaj koristi isti MEGA naziv i isti lokalni izvor (bez brisanja na MEGA). Kredencijali se ne prikazuju.</p>
        </div>

        @if ($rows->isEmpty())
            <p class="text-sm text-gray-600">Nema neuspjelih arhiva.</p>
        @else
            <div class="overflow-x-auto bg-white shadow rounded-lg border border-red-100">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-red-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th class="px-3 py-2">ID</th>
                            <th class="px-3 py-2">source_table</th>
                            <th class="px-3 py-2">source_id</th>
                            <th class="px-3 py-2">context_type</th>
                            <th class="px-3 py-2">original_local_path</th>
                            <th class="px-3 py-2">generated_file_name</th>
                            <th class="px-3 py-2">Greška</th>
                            <th class="px-3 py-2">Kreirano / ažurirano</th>
                            <th class="px-3 py-2">Lokalni fajl</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($rows as $item)
                            @php
                                $row = $item->archive;
                                $err = (string) ($row->error_message ?? '');
                            @endphp
                            <tr class="align-top">
                                <td class="px-3 py-2 whitespace-nowrap font-mono text-xs">{{ $row->id }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row->source_table }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $row->source_id }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row->context_type ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs break-all max-w-xs">{{ $row->original_local_path }}</td>
                                <td class="px-3 py-2 font-mono text-xs break-all max-w-xs">{{ $row->generated_file_name }}</td>
                                <td class="px-3 py-2 text-gray-700 max-w-sm break-words" title="{{ $err }}">{{ Str::limit($err, 120) }}</td>
                                <td class="px-3 py-2 text-xs text-gray-600 whitespace-nowrap">
                                    <div>{{ $row->created_at?->timezone($tz)->format('d.m.Y. H:i') }}</div>
                                    <div>{{ $row->updated_at?->timezone($tz)->format('d.m.Y. H:i') }}</div>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    @if ($item->local_file_exists)
                                        <span class="text-red-800">Da</span>
                                    @else
                                        <span class="text-red-700">Ne</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    @if ($item->local_file_exists)
                                        <form method="POST" action="{{ route('panel_admin.archive.failed.retry', $row, false) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-red-600 hover:text-red-900 text-xs font-medium underline">Pokušaj ponovo</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-400">Nedostupan</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-admin-panel-layout>
