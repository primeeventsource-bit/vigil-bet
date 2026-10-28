<x-layouts.marketing title="Features">
    <section class="border-b border-[var(--vg-line)]">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            <h1 class="font-[family-name:var(--font-display)] text-4xl font-bold sm:text-5xl">Features</h1>
            <p class="mt-5 max-w-2xl text-lg text-[var(--vg-slate)]">
                What each plan includes, read straight from the thing that enforces it.
            </p>
            {{--
                Not a boast - the reason this page can be trusted. A
                features list maintained beside the plans drifts from
                them within a release or two, and it always drifts the
                same way: it keeps promising the thing that was removed.
            --}}
            <p class="mt-3 max-w-2xl text-sm text-[var(--vg-muted)]">
                This page is generated from the same configuration the checkout and the
                entitlement checks use, so it cannot promise something the product does not
                actually unlock.
            </p>
        </div>
    </section>

    <section class="border-b border-[var(--vg-line)]">
        <div class="mx-auto max-w-5xl px-6 py-14">
            <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">
                {{ str_replace(':brand', config('brand.name'), $free['name'] ?? 'Free') }}
            </h2>
            <p class="mt-2 text-[var(--vg-slate)]">
                What an account gets without paying anything. No card, no trial clock.
            </p>

            <ul role="list" class="mt-5 grid gap-2.5 sm:grid-cols-2">
                @foreach (($free['features'] ?? []) as $feature)
                    <li class="flex gap-2.5 text-sm text-[var(--vg-slate)]">
                        <span aria-hidden="true" class="mt-0.5 text-[var(--vg-muted)]">&middot;</span>
                        <span>{{ str_replace(':brand', config('brand.name'), $feature) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    @foreach ($tiers as $key => $tier)
        <section @class(['border-b border-[var(--vg-line)]', 'bg-[var(--vg-navy)]' => $loop->odd])>
            <div class="mx-auto max-w-5xl px-6 py-14">
                <div class="flex flex-wrap items-baseline gap-3">
                    <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">
                        {{ str_replace(':brand', config('brand.name'), $tier['name']) }}
                    </h2>
                    <span class="font-[family-name:var(--font-mono)] text-sm text-[var(--vg-slate)]">
                        ${{ number_format($tier['monthly_cents'] / 100, 2) }}/month
                        &middot; ${{ number_format($tier['yearly_cents'] / 100, 2) }}/year
                    </span>
                    @if ($tier['highlight'] ?? false)
                        <span class="badge badge-edge">Most popular</span>
                    @endif
                </div>

                <p class="mt-2 text-[var(--vg-slate)]">
                    {{ str_replace(':brand', config('brand.name'), $tier['blurb']) }}
                </p>

                <ul role="list" class="mt-5 grid gap-2.5 sm:grid-cols-2">
                    @foreach ($tier['features'] as $feature)
                        <li class="flex gap-2.5 text-sm text-[var(--vg-slate)]">
                            <span aria-hidden="true" class="mt-0.5 text-[var(--vg-muted)]">&middot;</span>
                            <span>{{ str_replace(':brand', config('brand.name'), $feature) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endforeach

    <section>
        <div class="mx-auto max-w-5xl px-6 py-14">
            <div class="flex flex-wrap items-center gap-4">
                <a href="{{ route('pricing') }}"
                   class="rounded-lg bg-[var(--vg-green)] px-6 py-3 font-bold text-[var(--vg-night)]">
                    Compare plans
                </a>
                <a href="{{ route('how-it-works') }}"
                   class="rounded-lg border border-[var(--vg-line)] px-6 py-3 font-semibold text-[var(--vg-paper)]">
                    How it works
                </a>
            </div>

            <p class="mt-6 font-[family-name:var(--font-mono)] text-xs text-[var(--vg-muted)]">
                {{ config('brand.name') }} is an analytics product. It does not take bets or
                hold funds. {{ config('vigil.minimum_age') }}+ only.
            </p>
        </div>
    </section>
</x-layouts.marketing>
