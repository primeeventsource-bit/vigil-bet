<x-layouts.app title="Responsible gambling">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Responsible gambling</h1>

    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-[var(--vg-slate)]">
        {{ config('brand.name') }} is analysis, not advice, and it holds no money. If the betting
        has stopped being something you choose to do, the most useful thing on this page is the
        helpline at the bottom.
    </p>

    {{-- Self-exclusion --}}
    <section class="panel mt-6 p-6">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Lock yourself out</h2>

        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-[var(--vg-slate)]">
            You can lock yourself out for 7, 30 or 90 days. While it is running you cannot sign
            in — not on another device, not in another browser, and not by asking support.
        </p>

        {{--
            The warning is the feature. §9 says it cannot be undone
            early, and a customer who finds that out afterwards has been
            tricked by their own product.
        --}}
        <p class="mt-4 rounded-lg border border-[var(--vg-loss)] px-4 py-3 text-sm leading-relaxed">
            <strong>This cannot be shortened or cancelled.</strong> There is no button for it, no
            setting, and no process support can follow. The only thing that ends it is the time
            running out. Please be sure before you choose.
        </p>

        @error('days')
            <p class="mt-3 text-sm text-[var(--vg-loss)]">{{ $message }}</p>
        @enderror
        @error('acknowledged')
            <p class="mt-3 text-sm text-[var(--vg-loss)]">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('safety.exclude') }}" class="mt-5"
              onsubmit="return confirm('Lock yourself out? This cannot be undone early by anybody.')">
            @csrf

            <fieldset>
                <legend class="text-sm text-[var(--vg-slate)]">How long</legend>

                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    @foreach ($durations as $days)
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--vg-line)] p-4 has-checked:border-[var(--vg-loss)]">
                            <input type="radio" name="days" value="{{ $days }}" required
                                   class="mt-0.5 accent-[var(--vg-loss)]">
                            <span>
                                <span class="block font-semibold">{{ $days }} days</span>
                                <span class="block text-xs text-[var(--vg-slate)]">
                                    Until {{ now()->addDays($days)->toFormattedDateString() }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <label class="mt-5 flex items-start gap-3 text-sm">
                <input type="checkbox" name="acknowledged" value="1" required
                       class="mt-0.5 accent-[var(--vg-loss)]">
                <span class="text-[var(--vg-slate)]">
                    I understand that I will be locked out of {{ config('brand.name') }} for the
                    period I have chosen, that this cannot be shortened or cancelled by me or by
                    support, and that I will not be able to sign in until it ends.
                </span>
            </label>

            <button type="submit"
                    class="mt-5 rounded-lg border border-[var(--vg-loss)] px-4 py-2.5 font-semibold text-[var(--vg-loss)]">
                Lock me out
            </button>
        </form>
    </section>

    @if ($history->isNotEmpty())
        <section class="panel mt-5 p-6">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Your self-exclusions</h2>

            <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($history as $exclusion)
                    <li class="flex items-baseline justify-between gap-3 py-2.5">
                        <span>
                            {{ $exclusion->days }} days
                            <span class="block text-xs text-[var(--vg-slate)]">
                                {{ $exclusion->starts_at->toFormattedDateString() }}
                                &ndash; {{ $exclusion->ends_at->toFormattedDateString() }}
                            </span>
                        </span>
                        <span @class(['badge shrink-0', 'badge-pass' => ! $exclusion->isActive()])>
                            {{ $exclusion->isActive() ? 'Running' : 'Ended' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- The helpline, last and unmissable --}}
    <section class="panel mt-5 p-6">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">If you want to talk to somebody</h2>
        <p class="mt-2 text-sm text-[var(--vg-slate)]">
            {{ config('vigil.responsible_gambling_line') }}
        </p>
        <p class="mt-2 text-xs text-[var(--vg-slate)]">
            Free, confidential, and available all day and night. You do not have to be in crisis
            to call.
        </p>
    </section>
</x-layouts.app>
