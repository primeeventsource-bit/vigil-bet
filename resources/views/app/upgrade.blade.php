<x-layouts.app title="Upgrade">
    <div class="mx-auto max-w-xl">
        @php($brand = fn (?string $t) => str_replace(':brand', config('brand.name'), (string) $t))

        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">
            @if ($label)
                That one needs a bigger plan
            @else
                Upgrade
            @endif
        </h1>

        <div class="fx-panel mt-6 p-6">
            @if ($label && $tier)
                <p class="text-[var(--vg-paper)]">
                    You went looking for <strong>{{ $label }}</strong>. It comes with
                    <strong>{{ $brand($tier['name']) }}</strong>, and your account is on
                    <strong>{{ $brand(config('plans.tiers.'.$currentTier.'.name')) }}</strong>.
                </p>

                <p class="mt-5 flex items-baseline gap-1.5">
                    <span class="font-[family-name:var(--font-mono)] text-3xl font-bold tabular-nums" data-numeric>
                        ${{ rtrim(rtrim(number_format($tier['monthly_cents'] / 100, 2), '0'), '.') }}
                    </span>
                    <span class="text-sm text-[var(--vg-slate)]">/month</span>
                </p>

                <ul class="mt-5 space-y-2 text-sm">
                    @foreach ($tier['features'] as $feature)
                        <li class="flex gap-2.5">
                            <span aria-hidden="true" class="mt-0.5 shrink-0 text-[var(--vg-edge)]">&check;</span>
                            <span class="text-[var(--vg-slate)]">{{ $brand($feature) }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-[var(--vg-slate)]">
                    That feature is not on your plan.
                </p>
            @endif

            {{--
                Nothing is on sale yet: billing is step 6. Saying so is
                better than a button that takes a card for something the
                product cannot yet deliver.
            --}}
            <p class="mt-6 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-3 text-center text-xs text-[var(--vg-slate)]">
                Paid plans are not on sale yet — no market is proven, so there is nothing here
                worth charging for. <a class="text-[var(--vg-blue)] underline" href="{{ route('pricing') }}">See the plans</a>.
            </p>
        </div>

        <p class="mt-5 text-sm">
            <a class="text-[var(--vg-blue)] underline" href="{{ route('today') }}">Back to the dashboard</a>
        </p>
    </div>
</x-layouts.app>
