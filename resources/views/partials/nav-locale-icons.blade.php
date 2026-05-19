<div class="flex items-center gap-2">
    <a
        href="{{ url('/locale/cg') }}"
        class="inline-flex rounded transition focus:outline-none focus-visible:ring-2 focus-visible:ring-white/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#9e1321] {{ app()->getLocale() === 'cg' ? 'ring-2 ring-white ring-offset-2 ring-offset-[#9e1321]' : 'opacity-60 hover:opacity-100' }}"
        title="Crnogorski"
        aria-label="Crnogorski"
    >
        <img src="{{ asset('images/cg.png') }}" alt="" class="block h-6 w-auto" decoding="async" />
        <span class="sr-only">CG</span>
    </a>
    <a
        href="{{ url('/locale/en') }}"
        class="inline-flex rounded transition focus:outline-none focus-visible:ring-2 focus-visible:ring-white/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#9e1321] {{ app()->getLocale() === 'en' ? 'ring-2 ring-white ring-offset-2 ring-offset-[#9e1321]' : 'opacity-60 hover:opacity-100' }}"
        title="English"
        aria-label="English"
    >
        <img src="{{ asset('images/en.png') }}" alt="" class="block h-6 w-auto" decoding="async" />
        <span class="sr-only">EN</span>
    </a>
</div>
