@props(['compact' => false])

@php
    // Straight from config/plans.php, so what is advertised here and what
    // the product will enforce come from one place. Money in cents.
    $tiers = config('plans.tiers');
    // `:brand` resolves at render time; see config/plans.php.
    $brand = fn (string $text) => str_replace(':brand', config('brand.name'), $text);
    $money = fn (int $cents) => $cents === 0 ? 'Free' : '$'.rtrim(rtrim(number_format($cents / 100, 2), '0'), '.');
@endphp

<section class="relative isolate overflow-hidden border-b border-[var(--vg-line)] bg-[var(--vg-navy)]">
    <x-fx.section-glow class="left-1/2 top-0 h-56 w-[40rem] -translate-x-1/2 -translate-y-1/2 opacity-25" />

    {{-- Less top padding when the heading is hidden, or the section opens with a hole in it. --}}
    <div @class(['relative mx-auto max-w-6xl px-6 pb-20', 'pt-20' => ! $compact, 'pt-10' => $compact])>
        @unless ($compact)
            <h2 class="font-[family-name:var(--font-display)] text-3xl font-bold">Plans</h2>
            <div class="fx-rule mt-5 max-w-xs"></div>
        @endunless

        <div @class(['grid gap-5 lg:grid-cols-3', 'mt-10' => ! $compact])>
            @foreach ($tiers as $key => $tier)
                <div @class([
                    'fx-panel fx-panel-lift relative flex flex-col p-6',
                    'ring-1 ring-[color-mix(in_srgb,var(--vg-edge)_45%,transparent)]' => $tier['highlight'] ?? false,
                ])>
                    @if ($tier['highlight'] ?? false)
                        <span class="absolute -top-2.5 left-6 rounded-full bg-[var(--vg-paper)] px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-[var(--vg-night)]">
                            Most popular
                        </span>
                    @endif

                    <h3 class="font-[family-name:var(--font-display)] text-xl font-bold">{{ $brand($tier['name']) }}</h3>
                    <p class="mt-1 text-sm text-[var(--vg-slate)]">{{ $tier['blurb'] }}</p>

                    <p class="mt-5 flex items-baseline gap-1.5">
                        <span class="font-[family-name:var(--font-mono)] text-4xl font-bold tabular-nums" data-numeric>
                            {{ $money($tier['monthly_cents']) }}
                        </span>
                        @if ($tier['monthly_cents'] > 0)
                            <span class="text-sm text-[var(--vg-slate)]">/month</span>
                        @endif
                    </p>

                    @if ($tier['yearly_cents'] > 0)
                        <p class="mt-1 font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]" data-numeric>
                            or {{ $money($tier['yearly_cents']) }}/year — about two months free
                        </p>
                    @endif

                    <ul class="mt-6 flex-1 space-y-2.5 text-sm">
                        @foreach ($tier['features'] as $feature)
                            <li class="flex gap-2.5">
                                <span aria-hidden="true" class="mt-0.5 shrink-0 text-[var(--vg-edge)]">&check;</span>
                                <span class="text-[var(--vg-slate)]">{{ $brand($feature) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    {{--
                        Nothing is on sale yet: billing is step 6 and the
                        entitlements that would make a paid tier mean
                        anything are step 5. The button says so rather than
                        taking a card for a product that cannot yet deliver
                        the thing being charged for.
                    --}}
                    <p class="mt-6 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-2.5 text-center text-xs text-[var(--vg-slate)]">
                        @if ($tier['monthly_cents'] === 0)
                            <a href="{{ route('register') }}" class="font-semibold text-[var(--vg-edge)] hover:underline">
                                Create a free account &rarr;
                            </a>
                        @else
                            Not on sale yet — no market is proven, so there is nothing here worth charging for.
                        @endif
                    </p>
                </div>
            @endforeach
        </div>

        <p class="mt-6 text-xs text-[var(--vg-slate)]">
            {{ config('plans.trial_days') }}-day free trial on the paid tiers when they open.
            Downgrades and cancellations take effect at period end, and cancelling is one click.
        </p>
    </div>
</section>
