<x-control-layout page-title="Kontrola — prijava">
    <div class="max-w-lg mx-auto bg-white shadow-md rounded-lg px-6 py-8">
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Kontrola — prijava</h1>

        <form method="POST" action="{{ route('control.login.store', [], false) }}">
            @csrf

            <div>
                <x-input-label for="email" value="Email" />
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="password" value="Lozinka" />
                <div class="mt-1 grid w-full grid-cols-1" data-pw-wrapper>
                    <x-text-input id="password" class="col-start-1 row-start-1 min-w-0 block w-full pr-11"
                                  type="password"
                                  name="password"
                                  required autocomplete="current-password" />
                    @include('auth.partials.password-eye-toggle-button', [
                        'showText' => 'Prikaži',
                        'hideText' => 'Sakrij',
                    ])
                </div>
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div class="block mt-4">
                <label for="remember_me" class="inline-flex items-center">
                    <input id="remember_me" type="checkbox" class="rounded border-red-200 text-red-600 shadow-sm focus:ring-red-500" name="remember" value="1">
                    <span class="ms-2 text-sm text-gray-600">Zapamti me</span>
                </label>
            </div>

            <div class="flex justify-end mt-6">
                <x-primary-button>
                    Prijavite se
                </x-primary-button>
            </div>
        </form>
    </div>

    @include('auth.partials.password-toggle-script')
</x-control-layout>
