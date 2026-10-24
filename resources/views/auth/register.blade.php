<x-auth-card
    title="Create an account"
    :subtitle="config('brand.name').' sells information and tools. It never takes a bet or holds your money.'">

    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <x-field name="name" label="Name" autocomplete="name" autofocus />
        <x-field name="email" label="Email" type="email" autocomplete="email" />
        <x-field name="password" label="Password" type="password" autocomplete="new-password" />
        <x-field name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" />

        {{--
            The age gate. Two separate things and both are required: the
            date, which is arithmetic, and the box, which is a statement.

            `max` stops an honest mistake before the round trip. It is a
            convenience and never the gate - the server checks again, and
            the server is the only check that counts.
        --}}
        <fieldset class="space-y-3 rounded-md border border-[var(--vg-line)] p-4">
            <legend class="px-1 text-sm font-semibold text-[var(--vg-paper)]">
                Age check — {{ $minimumAge }}+ only
            </legend>

            <x-field
                name="birth_date"
                label="Date of birth"
                type="date"
                :max="$latestEligibleBirthDate"
                hint="We store only the year, never the full date." />

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="age_attestation" value="1" required
                       class="mt-0.5 rounded border-[var(--vg-line)] bg-[var(--vg-night)]"
                       @checked(old('age_attestation'))>
                <span>I confirm I am at least {{ $minimumAge }} years old.</span>
            </label>
        </fieldset>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="terms" value="1" required
                   class="mt-0.5 rounded border-[var(--vg-line)] bg-[var(--vg-night)]"
                   @checked(old('terms'))>
            <span>
                I accept the
                <a class="text-[var(--vg-blue)] underline" href="{{ route('legal.terms') }}">terms</a>,
                the
                <a class="text-[var(--vg-blue)] underline" href="{{ route('legal.privacy') }}">privacy policy</a>
                and the
                <a class="text-[var(--vg-blue)] underline" href="{{ route('legal.disclaimer') }}">disclaimer</a>.
                I understand there are no guarantees.
            </span>
        </label>

        <button type="submit"
                class="w-full rounded-md bg-[var(--vg-paper)] px-4 py-2.5 font-semibold text-[var(--vg-night)]">
            Create account
        </button>
    </form>

    <p class="mt-6 text-sm text-[var(--vg-slate)]">
        Already have an account?
        <a class="text-[var(--vg-blue)] underline" href="{{ route('login') }}">Sign in</a>
    </p>

    <p class="mt-4 text-xs text-[var(--vg-slate)]">{{ config('vigil.responsible_gambling_line') }}</p>
</x-auth-card>
