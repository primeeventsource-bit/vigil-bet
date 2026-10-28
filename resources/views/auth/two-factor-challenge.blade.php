<x-auth-card
    title="Two-factor code"
    subtitle="Enter the six digit code from your authenticator app, or one of your recovery codes.">

    <form method="POST" action="{{ route('two-factor.challenge') }}" class="space-y-5">
        @csrf

        <x-field
            name="code"
            label="Code"
            autocomplete="one-time-code"
            inputmode="numeric"
            autofocus
            hint="A recovery code works here too, and works only once." />

        <button type="submit"
                class="w-full rounded-md bg-[var(--vg-green)] px-4 py-2.5 font-semibold text-[var(--vg-night)]">
            Finish signing in
        </button>
    </form>
</x-auth-card>
