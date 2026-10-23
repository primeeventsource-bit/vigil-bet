<x-layouts.app title="Account">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Account</h1>

    <div class="panel mt-6 p-6">
        <h2 class="font-semibold">Security</h2>
        <p class="mt-1 text-sm text-[var(--vg-slate)]">
            Two-factor authentication is
            <strong>{{ auth()->user()->hasTwoFactorEnabled() ? 'on' : 'off' }}</strong>.
        </p>
        <p class="mt-3">
            <a class="text-[var(--vg-blue)] underline" href="{{ route('two-factor.show') }}">
                Manage two-factor authentication
            </a>
        </p>
    </div>
</x-layouts.app>
