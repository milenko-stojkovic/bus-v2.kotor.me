<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Admin – rezervacije
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
                    <form action="{{ route('staff.reservations.index') }}" method="get" class="mb-6 flex flex-wrap gap-2 items-end">
                        <div class="flex-1 min-w-[200px]">
                            <label for="q" class="block text-sm font-medium text-gray-700 mb-1">Pretraga (email, tablica, ime, ID transakcije)</label>
                            <input type="text" name="q" id="q" value="{{ old('q', $search) }}"
                                   class="block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm"
                                   placeholder="Pretraži...">
                        </div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800 focus:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            Pretraži
                        </button>
                        @if($search !== '')
                            <a href="{{ route('staff.reservations.index') }}" class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50 focus:outline-none transition">
                                Prikaži naredna 3h
                            </a>
                        @endif
                    </form>

                    @if($search === '')
                        <p class="text-sm text-gray-600 mb-4">Prikaz: rezervacije čiji drop-off termin je u naredna 3 sata. Za sve rezervacije koristite pretragu.</p>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-red-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Datum</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Drop-off</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Pick-up</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ime</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tablica</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fiskalizacija</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tx ID</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Akcije</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($reservations as $r)
                                    <tr>
                                        <td class="px-4 py-2 whitespace-nowrap text-gray-900">{{ $r->reservation_date?->format('d.m.Y') }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-gray-600">{{ $r->dropOffTimeSlot?->time_slot }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-gray-600">{{ $r->pickUpTimeSlot?->time_slot }}</td>
                                        <td class="px-4 py-2 text-gray-900">{{ $r->user_name }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $r->email }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-gray-900">{{ $r->license_plate }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap">
                                            <span class="px-2 py-0.5 rounded text-xs {{ $r->status === 'paid' ? 'bg-red-100 text-red-900' : 'bg-red-50 text-gray-800' }}">{{ $r->status }}</span>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap">
                                            @if($r->fiscalizationStatus() === 'completed')
                                                <span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-900">OK</span>
                                            @elseif($r->fiscalizationStatus() === 'failed')
                                                <span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-800">Ponovi</span>
                                            @elseif($r->fiscalizationStatus() === 'not_applicable')
                                                <span class="px-2 py-0.5 rounded text-xs bg-red-50 text-red-800">—</span>
                                            @else
                                                <span class="px-2 py-0.5 rounded text-xs bg-red-50 text-gray-600">Na čekanju</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ $r->merchant_transaction_id }}</td>
                                        <td class="px-4 py-2">
                                            <div class="flex flex-wrap gap-1">
                                                @if($r->postFiscalizationDataUnresolved)
                                                    <form action="{{ route('staff.reservations.retry-fiscalization', $r->id) }}" method="post" class="inline">
                                                        @csrf
                                                        <button type="submit" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800 hover:bg-red-200">Retry fiskalizaciju</button>
                                                    </form>
                                                    <form action="{{ route('staff.reservations.mark-resolved', $r->id) }}" method="post" class="inline">
                                                        @csrf
                                                        <button type="submit" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-50 text-gray-700 hover:bg-red-100">Označi rešeno</button>
                                                    </form>
                                                @endif
                                                <form action="{{ route('staff.reservations.resend-invoice', $r->id) }}" method="post" class="inline">
                                                    @csrf
                                                    <button type="submit" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800 hover:bg-red-200">Pošalji račun ponovo</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="px-4 py-8 text-center text-gray-500">
                                            {{ $search !== '' ? 'Nema rezultata pretrage.' : 'Nema rezervacija u naredna 3 sata.' }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($reservations->hasPages())
                        <div class="mt-4">
                            {{ $reservations->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
