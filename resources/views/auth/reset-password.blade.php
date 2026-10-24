<x-auth-card title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-field name="email" label="Email" type="email" :value="$email" autocomplete="email" />
        <x-field name="password" label="New password" type="password" autocomplete="new-password" autofocus />
        <x-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" />

        <button type="submit"
                class="w-full rounded-md bg-[var(--vg-paper)] px-4 py-2.5 font-semibold text-[var(--vg-night)]">
            Change password
        </button>
    </form>
</x-auth-card>
