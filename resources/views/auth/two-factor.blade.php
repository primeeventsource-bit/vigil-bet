<x-layouts.app title="Two-factor authentication">
    <div class="mx-auto max-w-xl">
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Two-factor authentication</h1>

        <p class="mt-2 text-sm text-[var(--vg-slate)]">
            A code from an app on your phone, on top of your password.
        </p>

        @if ($errors->any())
            <div role="alert" class="mt-4 rounded-md border border-[var(--vg-loss)] px-3 py-2 text-sm text-[var(--vg-loss)]">
                <ul class="space-y-1">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        @if ($recoveryCodes)
            {{--
                Shown exactly once, on the response that created them.
                A page that re-shows them on refresh is a page that leaves
                them on a screen in a coffee shop.
            --}}
            <div class="panel mt-6 border-[var(--vg-warning)] p-5">
                <h2 class="font-[family-name:var(--font-display)] font-semibold text-[var(--vg-warning)]">
                    Save these recovery codes now
                </h2>
                <p class="mt-1 text-sm text-[var(--vg-slate)]">
                    Each one works once, and they will not be shown again. They are how you get
                    in if you lose your phone.
                </p>
                <ul class="mt-3 grid grid-cols-2 gap-2 font-[family-name:var(--font-mono)] text-sm">
                    @foreach ($recoveryCodes as $code)
                        <li class="rounded bg-[var(--vg-night)] px-2 py-1">{{ $code }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="panel mt-6 p-6">
            @if ($enabled)
                <p class="badge badge-edge">On</p>

                <p class="mt-3 text-sm">
                    Two-factor authentication is on. You will be asked for a code when you sign in.
                </p>

                <form method="POST" action="{{ route('two-factor.destroy') }}" class="mt-5 space-y-4">
                    @csrf
                    @method('DELETE')

                    <x-field name="password" label="Confirm your password to turn it off" type="password"
                             autocomplete="current-password"
                             hint="Asked for so that somebody at an unlocked laptop cannot quietly remove it." />

                    <button type="submit" class="rounded-md border border-[var(--vg-loss)] px-4 py-2 text-sm font-semibold text-[var(--vg-loss)]">
                        Turn off two-factor authentication
                    </button>
                </form>
            @elseif ($pending)
                <p class="badge badge-warning">Setup not finished</p>

                <p class="mt-3 text-sm">
                    Scan this in your authenticator app, then enter a code it shows. Nothing is
                    switched on until a code has been proved to work.
                </p>

                <p class="mt-4 text-xs text-[var(--vg-slate)]">If you cannot scan, enter this key by hand:</p>
                <p class="mt-1 break-all rounded bg-[var(--vg-night)] px-3 py-2 font-[family-name:var(--font-mono)] text-sm">
                    {{ $secret }}
                </p>

                <p class="mt-3 break-all text-xs text-[var(--vg-slate)]">{{ $uri }}</p>

                <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-5 space-y-4">
                    @csrf
                    <x-field name="code" label="Code from your app" inputmode="numeric" autocomplete="one-time-code" />

                    <button type="submit" class="rounded-md bg-[var(--vg-paper)] px-4 py-2 text-sm font-semibold text-[var(--vg-night)]">
                        Finish setup
                    </button>
                </form>
            @else
                <p class="badge badge-pass">Off</p>

                <p class="mt-3 text-sm">
                    Your account is protected by a password alone.
                </p>

                <form method="POST" action="{{ route('two-factor.store') }}" class="mt-5">
                    @csrf
                    <button type="submit" class="rounded-md bg-[var(--vg-paper)] px-4 py-2 text-sm font-semibold text-[var(--vg-night)]">
                        Set up two-factor authentication
                    </button>
                </form>
            @endif
        </div>

        <p class="mt-6 text-sm">
            <a class="text-[var(--vg-blue)] underline" href="{{ route('account') }}">Back to account</a>
        </p>
    </div>
</x-layouts.app>
