@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator<\App\Models\User> $users */
    $users = $users ?? null;
    $advanceEnabled = (bool) ($advanceEnabled ?? false);
@endphp

<x-admin-panel-layout page-title="Agencije" nav-active="agencies">
    <div class="space-y-6">
        <header>
            <h1 class="text-2xl font-semibold text-gray-900">Agencije</h1>
            <p class="text-sm text-gray-600 mt-1">Lista korisnika/agencija i pregled avansa (read-only).</p>
        </header>

        <section class="bg-white shadow rounded-lg p-4 sm:p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-red-100 text-gray-600">
                            <th class="py-2 pr-4">Naziv</th>
                            <th class="py-2 pr-4">Email</th>
                            <th class="py-2 pr-4">Registracija</th>
                            <th class="py-2 pr-4">Avans</th>
                            <th class="py-2 pr-4">Rezervacije</th>
                            <th class="py-2 pr-4">Detalji</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $u)
                            @php
                                $bal = number_format((float) ($u->advance_balance ?? 0), 2, '.', '');
                            @endphp
                            <tr class="border-b border-red-100">
                                <td class="py-2 pr-4 font-medium">{{ $u->name }}</td>
                                <td class="py-2 pr-4">{{ $u->email }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $u->created_at?->format('d.m.Y.') ?? '—' }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">
                                    @if ($advanceEnabled)
                                        {{ $bal }} EUR
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ (int) ($u->reservations_count ?? 0) }}</td>
                                <td class="py-2 pr-4">
                                    <a class="text-red-700 underline font-medium" href="{{ route('panel_admin.agencies.show', $u, false) }}">
                                        Detalji
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        @if ($users->isEmpty())
                            <tr>
                                <td class="py-4 text-gray-600" colspan="6">Nema agencija.</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $users->links() }}
            </div>
        </section>
    </div>
</x-admin-panel-layout>

