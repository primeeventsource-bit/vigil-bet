<x-auth-card title="Sign in">
    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <x-field name="email" label="Email" type="email" autocomplete="email" autofocus />
        <x-field name="password" label="Password" type="password" autocomplete="current-password" />

        <label class="flex items-center gap-2 text-sm text-[var(--vg-slate)]">
            <input type="checkbox" name="remember" value="1"
                   class="rounded border-[var(--vg-line)] bg-[var(--vg-night)]">
            Keep me signed in
        </label>

        <button type="submit"
                class="w-full rounded-md bg-[var(--vg-paper)] px-4 py-2.5 font-semibold text-[var(--vg-night)]">
            Sign in
        </button>
    </form>

    <div class="mt-6 space-y-2 text-sm">
        <p>
            <a class="text-[var(--vg-blue)] underline" href="{{ route('password.request') }}">Forgot your password?</a>
        </p>
        <p class="text-[var(--vg-slate)]">
            No account?
            <a class="text-[var(--vg-blue)] underline" href="{{ route('register') }}">Create one</a>
        </p>
    </div>
</x-auth-card>
