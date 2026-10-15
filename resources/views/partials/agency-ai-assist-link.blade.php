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
    $languageNote = \App\Support\UiText::t(
        $uiGroup,
        'ai_assist_language',
        $isCg
            ? 'Možete postavljati pitanja i dobijati odgovore na svom jeziku.'
            : 'You can ask questions and receive answers in your preferred language.',
    );
    $anonymousHint = \App\Support\UiText::t(
        $uiGroup,
        'ai_assist_anonymous_hint',
        $isCg
            ? 'Ako ChatGPT prijavi grešku provjere, koristite ovu opciju.'
            : 'If ChatGPT shows a verification error, use this option.',
    );
    $anonymousOpen = \App\Support\UiText::t(
        $uiGroup,
        'ai_assist_anonymous_open',
        $isCg ? 'Kopiraj i otvori ChatGPT' : 'Copy and open ChatGPT',
    );
    $anonymousPaste = \App\Support\UiText::t(
        $uiGroup,
        'ai_assist_anonymous_paste',
        $isCg
            ? 'U ChatGPT-u nalijepite tekst (Ctrl+V ili Zalijepi) i pritisnite Enter.'
            : 'In ChatGPT, paste the text (Ctrl+V or Paste) and press Enter.',
    );
    $anonymousCopied = \App\Support\UiText::t(
        $uiGroup,
        'ai_assist_anonymous_copied',
        $isCg ? 'Tekst je kopiran.' : 'Text copied.',
    );
    $anonymousCopyFailed = \App\Support\UiText::t(
        $uiGroup,
        'ai_assist_anonymous_copy_failed',
        $isCg
            ? 'Automatsko kopiranje nije uspjelo. Kopirajte tekst iz polja ispod.'
            : 'Automatic copying failed. Copy the text from the box below.',
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
                    fallbackState: 'idle',
                    manualPrompt: '',
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
                    promptText() {
                        const browserLanguage = navigator.languages?.[0] || navigator.language || 'en';
                        return promptTemplate.replace('__BROWSER_LANGUAGE__', browserLanguage);
                    },
                    openChat() {
                        const prompt = this.promptText();
                        const url = 'https://chatgpt.com/?q=' + encodeURIComponent(prompt);
                        const tab = window.open(url, '_blank', 'noopener,noreferrer');
                        if (tab) {
                            tab.opener = null;
                        }
                    },
                    openAnonymous() {
                        const prompt = this.promptText();
                        const tab = window.open('https://chatgpt.com/', '_blank', 'noopener,noreferrer');
                        if (tab) {
                            tab.opener = null;
                        }
                        const writeText = navigator.clipboard && navigator.clipboard.writeText;
                        if (typeof writeText !== 'function') {
                            this.manualPrompt = prompt;
                            this.fallbackState = 'failed';
                            return;
                        }
                        writeText.call(navigator.clipboard, prompt).then(() => {
                            this.manualPrompt = '';
                            this.fallbackState = 'copied';
                        }).catch(() => {
                            this.manualPrompt = prompt;
                            this.fallbackState = 'failed';
                        });
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
            <p class="mt-1.5 text-xs leading-snug text-gray-600">{{ $languageNote }}</p>
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
            <p class="mt-3 text-xs leading-snug text-gray-600">{{ $anonymousHint }}</p>
            <button
                type="button"
                class="mt-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-800 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2"
                x-on:click="openAnonymous()"
            >
                {{ $anonymousOpen }}
            </button>
            <div class="mt-1.5 text-xs leading-snug text-gray-700" aria-live="polite">
                <p x-show="fallbackState === 'copied'" x-cloak>{{ $anonymousCopied }}</p>
                <p x-show="fallbackState === 'failed'" x-cloak>{{ $anonymousCopyFailed }}</p>
                <textarea
                    x-show="fallbackState === 'failed'"
                    x-cloak
                    readonly
                    rows="4"
                    x-model="manualPrompt"
                    class="mt-1.5 w-full rounded border border-gray-200 p-2 text-xs text-gray-800"
                    aria-label="{{ $anonymousCopyFailed }}"
                ></textarea>
                <p class="mt-1.5" x-show="fallbackState !== 'idle'" x-cloak>
                    {{ $anonymousPaste }}
                    <a href="https://chatgpt.com/" target="_blank" rel="noopener noreferrer" class="underline">https://chatgpt.com/</a>
                </p>
            </div>
        </div>
    </div>
@endif
