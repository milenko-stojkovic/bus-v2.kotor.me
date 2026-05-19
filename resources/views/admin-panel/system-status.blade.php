@php
    /** @var array<string, mixed> $status */
    $badge = fn (string $state) => match ($state) {
        'ok' => 'bg-red-100 text-red-900',
        'warn' => 'bg-yellow-100 text-yellow-900',
        'bad' => 'bg-red-100 text-red-900',
        default => 'bg-red-100 text-red-900',
    };
    $fmtTs = function (?string $iso): string {
        if ($iso === null || $iso === '') {
            return '—';
        }
        try {
            return \Carbon\Carbon::parse($iso)->timezone(config('app.timezone'))->format('d.m.Y. H:i');
        } catch (\Throwable) {
            return $iso;
        }
    };
    $fmtAge = function (?int $sec): string {
        if ($sec === null) {
            return '—';
        }
        if ($sec < 60) {
            return $sec.' s';
        }
        if ($sec < 3600) {
            return (int) floor($sec / 60).' min';
        }
        return (int) floor($sec / 3600).' h '.(int) floor(($sec % 3600) / 60).' min';
    };
@endphp

<x-admin-panel-layout page-title="Sistem status" nav-active="system-status">
    <div class="space-y-8">
        <p class="text-sm text-gray-600 max-w-3xl">
            Pregled stanja iz baze i operativnog heartbeat keša (<code class="text-xs bg-red-50 px-1 rounded">alerts:system-health</code>,
            <code class="text-xs bg-red-50 px-1 rounded">files:archive-private</code>). Samo čitanje — nema akcija, restarta ni živog MEGA poziva na učitavanju stranice.
        </p>

        @php $q = $status['queue']; @endphp
        <section class="bg-white shadow rounded-lg border border-red-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-base font-semibold text-gray-900">Queue</h2>
                <span class="text-xs font-medium px-2 py-0.5 rounded {{ $badge($q['section_status']) }}">{{ $q['section_label'] }}</span>
            </div>
            <dl class="text-sm text-gray-800 space-y-1">
                <div><span class="text-gray-500">Driver:</span> <span class="font-mono">{{ $q['driver'] }}</span></div>
                @if ($q['is_database'])
                    <div><span class="text-gray-500">Pending (nerezervovano):</span> {{ $q['pending_count'] ?? '—' }}</div>
                    <div><span class="text-gray-500">Stale (≥ {{ $q['stale_threshold_minutes'] }} min po <code class="text-xs">available_at</code>):</span> {{ $q['stale_count'] ?? '—' }}</div>
                    <div><span class="text-gray-500">Starost najstarijeg pending:</span> {{ $fmtAge($q['oldest_pending_age_seconds'] ?? null) }}</div>
                    @if (($q['stale_marker'] ?? null) !== null)
                        <div class="mt-2 pt-2 border-t border-red-100">
                            <span class="text-gray-500">Marker „prvo zapažanje” (keš):</span>
                            <ul class="mt-1 list-disc list-inside text-xs text-gray-700 space-y-0.5">
                                <li>Prvi put: {{ $fmtTs($q['stale_marker']['first_seen_at'] ?? null) }}</li>
                                @if (isset($q['stale_marker']['pending_stale_count']))
                                    <li>Pending stale tada: {{ $q['stale_marker']['pending_stale_count'] }}</li>
                                @endif
                            </ul>
                        </div>
                    @else
                        <div class="text-xs text-gray-500 mt-1">Nema aktivnog markera u kešu.</div>
                    @endif
                @else
                    <p class="text-xs text-gray-600">Metrike pending/stale važe samo za <span class="font-mono">database</span> driver.</p>
                @endif
            </dl>
        </section>

        @php $m = $status['mega']; @endphp
        <section class="bg-white shadow rounded-lg border border-red-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-base font-semibold text-gray-900">MEGA (zadnja dijagnostika iz keša)</h2>
                <span class="text-xs font-medium px-2 py-0.5 rounded {{ $badge($m['section_status']) }}">{{ $m['section_label'] }}</span>
            </div>
            @if ($m['never_checked'])
                <p class="text-sm text-gray-700">nije još provjereno</p>
            @else
                <dl class="text-sm text-gray-800 space-y-1">
                    <div><span class="text-gray-500">Zadnja dijagnostika:</span> {{ $fmtTs($m['last_diagnose_at']) }}</div>
                    <div><span class="text-gray-500">Rezultat:</span>
                        @if ($m['last_diagnose_ok'] === true)
                            <span class="text-red-900 font-medium">OK</span>
                        @elseif ($m['last_diagnose_ok'] === false)
                            <span class="text-red-800 font-medium">Neuspješno</span>
                        @else
                            —
                        @endif
                    </div>
                    @if (!empty($m['last_diagnose_error']))
                        <div><span class="text-gray-500">Greška:</span> <span class="text-red-900 break-words">{{ $m['last_diagnose_error'] }}</span></div>
                    @endif
                </dl>
            @endif
        </section>

        @php $a = $status['archive']; @endphp
        <section class="bg-white shadow rounded-lg border border-red-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-base font-semibold text-gray-900">Privatna arhiva (heartbeat + DB)</h2>
                <span class="text-xs font-medium px-2 py-0.5 rounded {{ $badge($a['section_status']) }}">{{ $a['section_label'] }}</span>
            </div>
            <dl class="text-sm text-gray-800 space-y-1">
                <div><span class="text-gray-500">Poslednji run (keš):</span> {{ $fmtTs($a['last_run_at']) }}</div>
                <div><span class="text-gray-500">Poslednji OK (keš):</span> {{ $fmtTs($a['last_ok_at']) }}</div>
                <div><span class="text-gray-500">Neuspjelih u bazi:</span> {{ $a['failed_archives_count'] }}</div>
                @if ($a['failed_archives_count'] > 0)
                    <div>
                        <a href="{{ route('panel_admin.archive.failed', [], false) }}" class="text-red-700 hover:underline text-sm font-medium">Otvori listu neuspjelih arhiva →</a>
                    </div>
                @endif
            </dl>
            @php
                $archSum = ($a['last_summary'] ?? null);
                $archSum = is_array($archSum) ? $archSum : null;
                $archFields = ['scanned', 'archived', 'failed', 'skipped', 'source', 'limit', 'dry_run', 'require_mega_health', 'timestamp'];
                $archHasKnown = false;
                if ($archSum !== null) {
                    foreach ($archFields as $kf) {
                        if (array_key_exists($kf, $archSum)) {
                            $archHasKnown = true;
                            break;
                        }
                    }
                }
                $archFailedN = array_key_exists('failed', $archSum ?? []) ? (int) $archSum['failed'] : 0;
                $archArchivedN = array_key_exists('archived', $archSum ?? []) ? (int) $archSum['archived'] : 0;
                $archDaNe = fn ($v) => $v ? 'Da' : 'Ne';
            @endphp
            @if ($archSum === null)
                <p class="text-xs text-gray-500 mt-2">Nema sačuvanog sažetka u kešu.</p>
            @elseif (! $archHasKnown)
                <p class="text-xs text-gray-500 mt-2">Sažetak u kešu nema poznata polja za prikaz.</p>
            @else
                <div class="mt-3 pt-3 border-t border-red-100">
                    <h3 class="text-xs font-semibold text-gray-600 mb-2">Poslednji sažetak arhive (keš)</h3>
                    <dl class="text-sm text-gray-800 space-y-1">
                        @if (array_key_exists('scanned', $archSum))
                            <div><span class="text-gray-500">Scanned:</span> {{ $archSum['scanned'] }}</div>
                        @endif
                        @if (array_key_exists('archived', $archSum))
                            <div>
                                <span class="text-gray-500">Archived:</span>
                                @if ($archArchivedN > 0 && $archFailedN === 0)
                                    <span class="text-red-900">{{ $archSum['archived'] }}</span>
                                @else
                                    {{ $archSum['archived'] }}
                                @endif
                            </div>
                        @endif
                        @if (array_key_exists('failed', $archSum))
                            <div>
                                <span class="text-gray-500">Failed:</span>
                                @if ($archFailedN > 0)
                                    <span class="text-red-700 font-medium">{{ $archSum['failed'] }}</span>
                                @else
                                    {{ $archSum['failed'] }}
                                @endif
                            </div>
                        @endif
                        @if (array_key_exists('skipped', $archSum))
                            <div><span class="text-gray-500">Skipped:</span> {{ $archSum['skipped'] }}</div>
                        @endif
                        @if (array_key_exists('source', $archSum) && $archSum['source'] !== '' && $archSum['source'] !== null)
                            <div><span class="text-gray-500">Source:</span> <span class="font-mono text-xs">{{ $archSum['source'] }}</span></div>
                        @endif
                        @if (array_key_exists('limit', $archSum))
                            <div><span class="text-gray-500">Limit:</span> {{ $archSum['limit'] }}</div>
                        @endif
                        @if (array_key_exists('dry_run', $archSum))
                            <div><span class="text-gray-500">Dry run:</span> {{ $archDaNe((bool) $archSum['dry_run']) }}</div>
                        @endif
                        @if (array_key_exists('require_mega_health', $archSum))
                            <div><span class="text-gray-500">Require MEGA health:</span> {{ $archDaNe((bool) $archSum['require_mega_health']) }}</div>
                        @endif
                        @if (array_key_exists('timestamp', $archSum) && $archSum['timestamp'] !== '' && $archSum['timestamp'] !== null)
                            <div><span class="text-gray-500">Timestamp:</span> {{ $fmtTs(is_string($archSum['timestamp']) ? $archSum['timestamp'] : (string) $archSum['timestamp']) }}</div>
                        @endif
                    </dl>
                </div>
            @endif
        </section>

        @php $f = $status['fiscalization']; @endphp
        <section class="bg-white shadow rounded-lg border border-red-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-base font-semibold text-gray-900">Fiskalizacija (naknadno)</h2>
                <span class="text-xs font-medium px-2 py-0.5 rounded {{ $badge($f['section_status']) }}">{{ $f['section_label'] }}</span>
            </div>
            <p class="text-sm text-gray-800">
                Nerešenih <code class="text-xs bg-red-50 px-1 rounded">post_fiscalization_data</code> starijih od 2h:
                <strong>{{ $f['unresolved_over_2h'] }}</strong>
            </p>
        </section>

        @php $fj = $status['failed_jobs']; @endphp
        <section class="bg-white shadow rounded-lg border border-red-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-base font-semibold text-gray-900">Neuspjeli poslovi</h2>
                <span class="text-xs font-medium px-2 py-0.5 rounded {{ $badge($fj['section_status']) }}">{{ $fj['section_label'] }}</span>
            </div>
            <p class="text-sm text-gray-800">
                Broj u <code class="text-xs bg-red-50 px-1 rounded">failed_jobs</code> (zadnjih 24h):
                <strong>{{ $fj['failed_last_24h'] }}</strong>
            </p>
        </section>

        @php $al = $status['admin_alerts']; @endphp
        <section class="bg-white shadow rounded-lg border border-red-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-base font-semibold text-gray-900">Admin upozorenja (kritična, otvorena)</h2>
                <span class="text-xs font-medium px-2 py-0.5 rounded {{ $badge($al['section_status']) }}">{{ $al['section_label'] }}</span>
            </div>
            <p class="text-sm text-gray-800 mb-2">Otvorenih kritičnih: <strong>{{ $al['open_critical_count'] }}</strong></p>
            @if ($al['latest_open_critical']->isEmpty())
                <p class="text-xs text-gray-500">Nema prikaza.</p>
            @else
                <ul class="text-sm space-y-2">
                    @foreach ($al['latest_open_critical'] as $row)
                        <li class="border-b border-gray-50 pb-2 last:border-0">
                            <span class="font-medium text-gray-900">{{ $row->title }}</span>
                            <span class="text-gray-500 text-xs"> — {{ $row->created_at?->timezone(config('app.timezone'))->format('d.m.Y. H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-3">
                <a href="{{ route('panel_admin.dashboard', [], false) }}" class="text-red-700 hover:underline text-sm font-medium">Upozorenja / Informacije →</a>
            </p>
        </section>

        @php $sh = $status['system_health']; @endphp
        <section class="bg-white shadow rounded-lg border border-red-100 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-base font-semibold text-gray-900">Sistemsko zdravlje (heartbeat)</h2>
                <span class="text-xs font-medium px-2 py-0.5 rounded {{ $badge($sh['section_status']) }}">{{ $sh['section_label'] }}</span>
            </div>
            <dl class="text-sm text-gray-800 space-y-1">
                <div><span class="text-gray-500">Poslednji run komande:</span> {{ $fmtTs($sh['last_run_at']) }}</div>
                <div><span class="text-gray-500">Poslednji OK završetak:</span> {{ $fmtTs($sh['last_ok_at']) }}</div>
            </dl>
        </section>
    </div>
</x-admin-panel-layout>
