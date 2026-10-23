<x-auth-card
    title="Confirm your email"
    subtitle="We sent a link to the address you signed up with. Open it to finish setting up.">

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit"
                class="w-full rounded-md bg-[var(--vg-edge)] px-4 py-2.5 font-semibold text-[var(--vg-night)]">
            Send another link
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4">
        @csrf
        <button type="submit" class="text-sm text-[var(--vg-slate)] underline">Sign out</button>
    </form>
</x-auth-card>
