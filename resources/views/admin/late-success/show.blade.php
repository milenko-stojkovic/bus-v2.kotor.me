<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Late Success — detalji
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if(session('message'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('message') }}</div>
            @endif
            @if(session('error'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Privremeni podaci (temp_data) #{{ $row->id }}</h3>
                    <a href="{{ route('staff.late-success.index') }}" class="text-sm text-red-600 hover:text-red-800">Nazad na listu</a>
                </div>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div><dt class="font-semibold text-gray-700">merchant_transaction_id</dt><dd class="font-mono text-xs text-gray-600">{{ $row->merchant_transaction_id }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">status</dt><dd>{{ $row->status }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">resolution_reason</dt><dd>{{ $row->resolution_reason ?? '-' }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">reservation_date</dt><dd>{{ $row->reservation_date?->format('d.m.Y') }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">drop_off slot</dt><dd>{{ $row->dropOffTimeSlot?->time_slot ?? '-' }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">pick_up slot</dt><dd>{{ $row->pickUpTimeSlot?->time_slot ?? '-' }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">user_name</dt><dd>{{ $row->user_name }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">email</dt><dd>{{ $row->email }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">country</dt><dd>{{ $row->country }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">license_plate</dt><dd>{{ $row->license_plate }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">vehicle_type_id</dt><dd>{{ $row->vehicle_type_id }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">price</dt><dd>{{ $row->vehicleType?->price ?? '-' }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">callback_error_code</dt><dd>{{ $row->callback_error_code ?? '-' }}</dd></div>
                    <div><dt class="font-semibold text-gray-700">callback_error_reason</dt><dd>{{ $row->callback_error_reason ?? '-' }}</dd></div>
                </dl>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-3">Sirovi callback payload</h3>
                <pre class="text-xs bg-red-50 border border-red-100 rounded p-3 overflow-x-auto">{{ json_encode($row->raw_callback_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' }}</pre>
            </div>

            @if($row->status === \App\Models\TempData::STATUS_LATE_MANUAL_REVIEW)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-3">Akcije</h3>
                    <div class="flex flex-wrap gap-2">
                        <form method="post" action="{{ route('staff.late-success.force', $row->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700">
                                Prisilno kreiraj rezervaciju
                            </button>
                        </form>
                        <form method="post" action="{{ route('staff.late-success.reject', $row->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700">
                                Odbij
                            </button>
                        </form>
                    </div>
                    <p class="mt-3 text-xs text-gray-500">
                        Admin override koristi snapshot iz temp_data kao izvor istine i postavlja resolution_reason radi audita.
                    </p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
