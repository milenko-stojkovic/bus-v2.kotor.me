<x-admin-panel-layout :page-title="$title" :nav-active="$navActive">
    <div class="bg-white shadow rounded-lg p-6 border border-dashed border-red-100">
        <h1 class="text-lg font-semibold text-gray-900 mb-2">{{ $title }}</h1>
        <p class="text-gray-600">{{ $lead }}</p>
    </div>
</x-admin-panel-layout>
