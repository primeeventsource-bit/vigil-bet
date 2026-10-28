@props(['compact' => false])

@php
    /*
     * Straight from config/plans.php, so what is advertised here and
     * what the product will enforce come from one place. Money in cents.
     *
     * Four cards: the trial and the three sold tiers. `guest` and
     * `expired` are filtered out - they are states the application
     * resolves people into, not things anybody chooses, and a card
     * offering "Not started" would be an odd thing to read.
     */
    $tiers = collect(config('plans.tiers'))
        ->except(['guest', 'expired'])
        ->all();
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

        <div @class(['grid gap-5 lg:grid-cols-4', 'mt-10' => ! $compact])>
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
                    @if ($key === 'trial')
                        {{--
                            The one card that can be acted on today. A
                            signed-in account goes to the activation
                            page; everybody else registers first, which
                            is the whole of "account required".
                        --}}
                        <a href="{{ auth()->check() ? route('trial') : route('register') }}"
                           class="mt-6 block rounded-lg bg-[var(--vg-paper)] px-4 py-3 text-center text-sm font-bold uppercase tracking-wide text-[var(--vg-night)]">
                            Start free {{ config('plans.trial_hours') }}-hour trial
                        </a>

                        {{--
                            Four sentences, kept together and kept exact.
                            Each answers the question somebody is
                            actually asking before they hand over an
                            address, and the last two are the ones a
                            free trial is usually vague about.
                        --}}
                        <p class="mt-3 text-center text-[11px] leading-relaxed text-[var(--vg-muted)]">
                            Account registration required. No credit card required.
                            Trial expires automatically after {{ config('plans.trial_hours') }} hours.
                            No automatic charges.
                        </p>
                    @else
                        <p class="mt-6 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-2.5 text-center text-xs text-[var(--vg-slate)]">
                            Not on sale yet — no market is proven, so there is nothing here worth charging for.
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="mt-6 text-xs text-[var(--vg-slate)]">
            The trial does not renew and does not become a subscription &mdash; it simply ends,
            and your account stays open. Downgrades and cancellations take effect at period end,
            and cancelling is one click.
        </p>
    </div>
</section>
