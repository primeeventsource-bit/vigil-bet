<x-layouts.marketing>
    <section class="mx-auto max-w-6xl px-6 py-20">
        <p class="badge badge-edge">{{ config('brand.tagline') }}</p>

        <h1 class="mt-5 max-w-3xl font-[family-name:var(--font-display)] text-5xl font-bold leading-tight">
            Smarter bets.
            <span class="text-[var(--vg-edge)]">Sharper edges.</span>
        </h1>

        <p class="mt-5 max-w-2xl text-lg text-[var(--vg-slate)]">
            {{ config('brand.description') }}
        </p>

        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('register') }}"
               class="rounded-md bg-[var(--vg-edge)] px-5 py-2.5 font-semibold text-[var(--vg-night)]">
                Start free
            </a>
            <a href="{{ route('how-it-works') }}"
               class="rounded-md border border-[var(--vg-line)] px-5 py-2.5 font-semibold">
                How it works
            </a>
        </div>

        <p class="mt-4 text-sm text-[var(--vg-slate)]">
            No card needed. {{ config('vigil.minimum_age') }}+ only.
        </p>
    </section>

    <section class="mx-auto max-w-6xl px-6 pb-20">
        <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">Three steps</h2>

        <ol class="mt-6 grid gap-5 sm:grid-cols-3">
            @foreach ($steps as $i => $step)
                <li class="panel p-5">
                    <p class="font-[family-name:var(--font-mono)] text-sm text-[var(--vg-edge)]">
                        0{{ $i + 1 }}
                    </p>
                    <h3 class="mt-2 font-semibold">{{ $step['heading'] }}</h3>
                    <p class="mt-1 text-sm text-[var(--vg-slate)]">{{ $step['body'] }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    <section class="mx-auto max-w-6xl px-6 pb-24">
        <div class="panel p-6">
            <h2 class="font-[family-name:var(--font-display)] text-xl font-bold">What we will not do</h2>
            <ul class="mt-4 grid gap-2 text-sm text-[var(--vg-slate)] sm:grid-cols-2">
                <li>Never take a bet or hold your money.</li>
                <li>Never sell a pick from a market we have not proven.</li>
                <li>Never show a win rate without its sample size.</li>
                <li>Never make cancelling difficult.</li>
            </ul>
        </div>
    </section>
</x-layouts.marketing>
