@php
    /** @var \App\Models\User $user */
    /** @var \App\Models\VehicleCategoryChangeRequest $categoryChangeRequest */
    $req = $categoryChangeRequest;
    $isPending = $req->status === \App\Models\VehicleCategoryChangeRequest::STATUS_PENDING;

    $statusLabel = match ($req->status) {
        \App\Models\VehicleCategoryChangeRequest::STATUS_PENDING => 'Na čekanju',
        \App\Models\VehicleCategoryChangeRequest::STATUS_APPROVED => 'Prihvaćen',
        \App\Models\VehicleCategoryChangeRequest::STATUS_REJECTED => 'Odbijen',
        default => $req->status,
    };
@endphp

<x-admin-panel-layout page-title="Zahtjev za promjenu kategorije" nav-active="agencies">
    <div class="space-y-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Zahtjev za promjenu kategorije vozila</h1>
                <div class="text-sm text-gray-600 mt-1">
                    Agencija: {{ $user->name }} ({{ $user->email }})
                </div>
            </div>
            <div class="flex flex-wrap gap-3 text-sm">
                <a href="{{ route('panel_admin.agencies.show', $user, false) }}" class="text-red-700 underline font-medium">
                    Detalj agencije
                </a>
                <a href="{{ route('panel_admin.dashboard', [], false) }}" class="text-red-700 underline font-medium">
                    Upozorenja / Informacije
                </a>
            </div>
        </header>

        @if (session('status'))
            <div class="rounded-md bg-red-50 p-3 text-sm text-red-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <section class="bg-white shadow rounded-lg p-4 sm:p-6 space-y-4">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                <div>
                    <dt class="font-medium text-gray-600">Datum zahtjeva</dt>
                    <dd class="mt-1 text-gray-900">{{ $req->created_at?->format('d.m.Y. H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Registarska tablica</dt>
                    <dd class="mt-1 text-gray-900 font-medium">{{ $req->license_plate }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Stara kategorija</dt>
                    <dd class="mt-1 text-gray-900">{{ $req->oldVehicleType?->formatLabel('cg', 'EUR') ?? ('#'.$req->old_vehicle_type_id) }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Tražena kategorija</dt>
                    <dd class="mt-1 text-gray-900">{{ $req->requestedVehicleType?->formatLabel('cg', 'EUR') ?? ('#'.$req->requested_vehicle_type_id) }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-600">Status</dt>
                    <dd class="mt-1 text-gray-900">{{ $statusLabel }}</dd>
                </div>
                @if ($req->reviewed_at)
                    <div>
                        <dt class="font-medium text-gray-600">Obrada</dt>
                        <dd class="mt-1 text-gray-900">{{ $req->reviewed_at->format('d.m.Y. H:i') }}</dd>
                    </div>
                @endif
                @if ($req->approved_notification_sent_at || $req->rejected_notification_sent_at)
                    <div>
                        <dt class="font-medium text-gray-600">Obavještenje agenciji</dt>
                        <dd class="mt-1 text-gray-900">
                            @if ($req->approved_notification_sent_at)
                                Poslato (odobrenje) {{ $req->approved_notification_sent_at->format('d.m.Y. H:i') }}
                            @elseif ($req->rejected_notification_sent_at)
                                Poslato (odbijanje) {{ $req->rejected_notification_sent_at->format('d.m.Y. H:i') }}
                            @endif
                        </dd>
                    </div>
                @endif
                @if ($req->status === \App\Models\VehicleCategoryChangeRequest::STATUS_REJECTED && $req->rejection_reason)
                    <div class="sm:col-span-2">
                        <dt class="font-medium text-gray-600">Razlog odbijanja</dt>
                        <dd class="mt-1 text-gray-900 whitespace-pre-wrap">{{ $req->rejection_reason }}</dd>
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <dt class="font-medium text-gray-600">Prilozi</dt>
                    <dd class="mt-1">
                        @include('admin-panel.agencies.partials.category-change-attachments', ['user' => $user, 'req' => $req])
                    </dd>
                </div>
            </dl>

            @if ($isPending)
                <div class="pt-4 border-t border-red-100 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('panel_admin.agencies.vehicle_category_change_requests.approve', ['user' => $user->id, 'request' => $req->id], false) }}">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center rounded-md bg-red-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-600"
                                onclick="return confirm('Prihvatiti zahtjev i reaktivirati vozilo?');">
                            Prihvati
                        </button>
                    </form>
                    <form method="POST" action="{{ route('panel_admin.agencies.vehicle_category_change_requests.reject', ['user' => $user->id, 'request' => $req->id], false) }}" class="space-y-2">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700" for="reject-reason-{{ $req->id }}">Razlog odbijanja</label>
                        <textarea id="reject-reason-{{ $req->id }}" name="reason" rows="2" required
                                  class="block w-full max-w-md rounded-md border-gray-300 shadow-sm text-sm"
                                  placeholder="Obrazloženje za agenciju">{{ old('reason') }}</textarea>
                        <button type="submit"
                                class="inline-flex items-center rounded-md bg-red-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-600"
                                onclick="return confirm('Odbiti zahtjev?');">
                            Odbij
                        </button>
                    </form>
                </div>
            @else
                <p class="pt-4 border-t border-red-100 text-sm text-gray-600">
                    Zahtjev je već obrađen. Prikaz je samo za pregled.
                </p>
            @endif
        </section>
    </div>
</x-admin-panel-layout>
