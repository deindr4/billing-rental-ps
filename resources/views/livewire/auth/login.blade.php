<div class="min-h-screen grid place-items-center p-4">
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <img src="{{ \App\Support\Tema::logoAtauBawaan() }}" alt="Logo" class="mx-auto h-28 w-28 object-contain mb-3">
            <h1 class="text-xl font-semibold">{{ config('app.name') }}</h1>
            <p class="text-sm text-muted">Masuk untuk melanjutkan</p>
        </div>

        <form wire:submit="masuk" class="surface p-5 space-y-4">
            <div>
                <label for="login" class="block text-sm mb-1.5">Email atau username</label>
                <input id="login" type="text" wire:model="login" class="input"
                       autocomplete="username" autofocus>
                @error('login')
                    <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm mb-1.5">Password</label>
                <input id="password" type="password" wire:model="password" class="input"
                       autocomplete="current-password">
                @error('password')
                    <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-muted">
                <input type="checkbox" wire:model="remember">
                Ingat saya
            </label>

            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="masuk">Masuk</span>
                <span wire:loading wire:target="masuk">Memproses...</span>
            </button>
        </form>
    </div>
</div>
