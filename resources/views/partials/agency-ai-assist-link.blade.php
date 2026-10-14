@php
    $variant = $variant ?? 'guest';
    $localeKey = app()->getLocale() === 'cg' ? 'cg' : 'en';
    $uiGroup = $variant === 'panel' ? 'panel' : 'landing';
    $isCg = $localeKey === 'cg';
    $label = \App\Support\UiText::t(
        $uiGroup,
        'ai_assist_label',
        $isCg ? 'Pomoć preko ChatGPT-a' : 'ChatGPT help',
    );
    $hint = \App\Support\UiText::t(
        $uiGroup,
        'ai_assist_hint',
        $isCg
            ? 'ChatGPT će se otvoriti u novom tabu. Pritisnite Enter ili Pošalji da razgovor počne.'
            : 'ChatGPT opens in a new tab. Press Enter or Send to start the conversation.',
    );
    $hintParts = preg_split('/(?<=\.)\s+/u', $hint, 2) ?: [$hint];
    $hintLead = $hintParts[0];
    $hintAction = $hintParts[1] ?? '';
    $openLabel = \App\Support\UiText::t(
        $uiGroup,
        'ai_assist_open',
        $isCg ? 'Otvori ChatGPT' : 'Open ChatGPT',
    );
    $closeLabel = \App\Support\UiText::t(
        $uiGroup,
        'ai_assist_close',
        $isCg ? 'Zatvori' : 'Close',
    );
    $visible = request()->routeIs('landing', 'login', 'register', 'panel.*');
    static $agencyAiAssistSeq = 0;
    $agencyAiAssistSeq++;
    $dialogId = 'agency-ai-assist-dialog-'.$agencyAiAssistSeq;
    $promptTemplate = 'You help people use bus.kotor.me as a prospective or registered agency. Before answering, read https://bus.kotor.me/llms.txt. That guide is the authority for documented agency behavior. If you cannot access the guide, say so clearly and do not guess. Do not invent undocumented portal features. Start in this browser language: __BROWSER_LANGUAGE__. After your first reply, use the language of later user messages. Never ask for passwords, payment card details, or other secrets. Briefly introduce yourself and ask how you can help.';
    $focusRing = $variant === 'panel'
        ? 'focus-visible:ring-white/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#9e1321]'
        : 'focus-visible:ring-red-600 focus-visible:ring-offset-2';
    $iconColor = $variant === 'panel' ? 'text-white' : 'text-red-800';
@endphp
@if ($visible)
    @once
        <script>
            window.agencyAiAssist = function () {
                const promptTemplate = {!! json_encode($promptTemplate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) !!};
                return {
                    open: false,
                    close() {
                        this.open = false;
                    },
                    toggle() {
                        this.open = ! this.open;
                        if (this.open) {
                            this.$nextTick(() => {
                                if (this.$refs.openChat) {
                                    this.$refs.openChat.focus();
                                }
                            });
                        }
                    },
                    openChat() {
                        const browserLanguage = navigator.languages?.[0] || navigator.language || 'en';
                        const prompt = promptTemplate.replace('__BROWSER_LANGUAGE__', browserLanguage);
                        const url = 'https://chatgpt.com/?q=' + encodeURIComponent(prompt);
                        const tab = window.open(url, '_blank', 'noopener,noreferrer');
                        if (tab) {
                            tab.opener = null;
                        }
                    },
                };
            };
        </script>
    @endonce
    <div
        class="relative"
        data-agency-ai-assist
        x-data="agencyAiAssist()"
        x-on:keydown.escape.window="close()"
        @click.outside="close()"
    >
        <button
            type="button"
            class="inline-flex items-center justify-center rounded p-1 {{ $iconColor }} transition opacity-90 hover:opacity-100 focus:outline-none focus-visible:ring-2 {{ $focusRing }}"
            x-on:click="toggle()"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="dialog"
            aria-controls="{{ $dialogId }}"
            title="{{ $label }}"
            aria-label="{{ $label }}"
        >
            <span class="inline-flex h-6 w-6 items-center justify-center text-sm font-bold leading-none tracking-tight" aria-hidden="true">AI</span>
            <span class="sr-only">{{ $label }}</span>
        </button>
        <div
            id="{{ $dialogId }}"
            x-show="open"
            x-cloak
            role="dialog"
            aria-modal="false"
            aria-label="{{ $label }}"
            class="absolute end-0 top-full z-30 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-md bg-white p-3 text-left text-sm text-gray-900 shadow-lg ring-1 ring-black/10"
        >
            <p>{{ $hintLead }}</p>
            @if ($hintAction !== '')
                <p class="mt-1.5 font-semibold">{{ $hintAction }}</p>
            @endif
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    x-ref="openChat"
                    class="rounded-md bg-[#9e1321] px-3 py-2 text-xs font-semibold text-white hover:bg-red-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2"
                    x-on:click="openChat()"
                >
                    {{ $openLabel }}
                </button>
                <button
                    type="button"
                    class="rounded-md px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2"
                    x-on:click="close()"
                >
                    {{ $closeLabel }}
                </button>
            </div>
        </div>
    </div>
@endif
