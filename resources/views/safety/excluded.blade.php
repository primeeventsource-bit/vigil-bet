<x-layouts.marketing title="You are locked out">
    <section class="mx-auto max-w-xl px-6 py-24 text-center">
        <h1 class="font-[family-name:var(--font-display)] text-3xl font-bold">
            You are locked out, as you asked
        </h1>

        @if ($exclusion)
            <p class="mt-5 text-[var(--vg-slate)]">
                You chose a {{ $exclusion['days'] }}-day self-exclusion. It lifts in
                <strong class="text-[var(--vg-paper)]">{{ $exclusion['remaining'] }}</strong>.
            </p>
        @else
            <p class="mt-5 text-[var(--vg-slate)]">
                You have a self-exclusion running. You will be able to sign in when it ends.
            </p>
        @endif

        {{--
            No way out, and no link back in. §9 says a self-exclusion
            cannot be undone early, and a page offering a "contact
            support" button would be the way round it - support cannot
            lift one either, and saying so here is kinder than letting
            somebody spend an evening trying.
        --}}
        <p class="mt-6 text-sm leading-relaxed text-[var(--vg-slate)]">
            This cannot be shortened or cancelled — not by you, and not by support. There is no
            process for it, because a lock somebody can talk their way out of is not a lock.
        </p>

        <div class="mt-10 rounded-xl border border-[var(--vg-line)] p-6">
            <p class="text-sm font-semibold">If you want to talk to somebody</p>
            <p class="mt-2 text-sm text-[var(--vg-slate)]">
                {{ config('vigil.responsible_gambling_line') }}
            </p>
            <p class="mt-3 text-xs text-[var(--vg-slate)]">
                Free, confidential, and available all day and night.
            </p>
        </div>

        <p class="mt-8 text-xs text-[var(--vg-slate)]">
            Your account, your logged bets and your record are all untouched and will be where you
            left them.
        </p>
    </section>
</x-layouts.marketing>
