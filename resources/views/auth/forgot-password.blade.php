<x-auth-card
    title="Reset your password"
    subtitle="We will email a link. For your security the answer is the same whether or not the address has an account.">

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf
        <x-field name="email" label="Email" type="email" autocomplete="email" autofocus />

        <button type="submit"
                class="w-full rounded-md bg-[var(--vg-edge)] px-4 py-2.5 font-semibold text-[var(--vg-night)]">
            Email a reset link
        </button>
    </form>
</x-auth-card>
