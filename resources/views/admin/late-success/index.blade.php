<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Admin — Late Success ručni pregled
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('message'))
                <div class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('message') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="get" action="{{ route('staff.late-success.index') }}" class="mb-6 grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                        <div>
                            <label for="date" class="block text-sm font-medium text-gray-700 mb-1">Datum</label>
                            <input id="date" name="date" type="date" value="{{ $filters['date'] }}"
                                   class="block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm">
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="status" name="status" class="block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm">
                                <option value="">Svi</option>
                                @foreach(['late_success', 'late_manual_review', 'processed', 'late_rejected'] as $s)
                                    <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ $s }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="resolution_reason" class="block text-sm font-medium text-gray-700 mb-1">Razlog rezolucije</label>
                            <select id="resolution_reason" name="resolution_reason" class="block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm">
                                <option value="">Sve</option>
                                @foreach(['admin_forced', 'admin_rejected'] as $reason)
                                    <option value="{{ $reason }}" @selected($filters['resolution_reason'] === $reason)>{{ $reason }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                                Filtriraj
                            </button>
                            <a href="{{ route('staff.late-success.index') }}" class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50">
                                Reset
                            </a>
                        </div>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-red-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tx ID</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Datum rezervacije</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Razlog rezolucije</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Akcija</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($rows as $row)
                                    <tr>
                                        <td class="px-4 py-2 font-mono text-xs text-gray-600">{{ $row->merchant_transaction_id }}</td>
                                        <td class="px-4 py-2 text-gray-900">{{ $row->reservation_date?->format('d.m.Y') }}</td>
                                        <td class="px-4 py-2 text-gray-900">{{ $row->status }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $row->resolution_reason ?? '-' }}</td>
                                        <td class="px-4 py-2">
                                            <a href="{{ route('staff.late-success.show', $row->id) }}" class="text-red-600 hover:text-red-800">Detalji</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">Nema zapisa za prikaz.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($rows->hasPages())
                        <div class="mt-4">{{ $rows->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
