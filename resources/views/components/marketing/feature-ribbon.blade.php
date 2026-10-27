@props(['features'])

{{--
    The callout ribbon from the reference layout: five things the product
    does, under the hero and above the odds.

    Static, not links, which is how the reference draws it and also the
    only honest option here. Three of the five land behind the sign-in -
    recording a bet, building a parlay, the full history - and a
    marketing page that sends a visitor to a login screen they did not
    ask for has spent the click on nothing. The hero's two buttons are
    where this page asks to be clicked.
--}}
<section aria-label="What {{ config('brand.name') }} does"
         class="border-b border-[var(--vg-line)] bg-[var(--vg-navy)]">
    <ul role="list"
        class="mx-auto grid max-w-7xl grid-cols-2 gap-px bg-[var(--vg-line)] sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($features as $feature)
            <li class="flex flex-col items-center gap-2 bg-[var(--vg-navy)] px-4 py-6 text-center">
                {{--
                    Decorative: the heading beneath says the same thing in
                    words, so an icon announced as well would be read
                    twice. Silver rather than green - the green means a
                    proven edge and must not also be furniture.
                --}}
                <x-app.icon :name="$feature['icon']" class="h-5 w-5 text-[var(--vg-muted)]" />

                <h3 class="text-xs font-bold uppercase tracking-wide text-[var(--vg-paper)]">
                    {{ $feature['heading'] }}
                </h3>

                <p class="font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-muted)]">
                    {{ $feature['body'] }}
                </p>
            </li>
        @endforeach
    </ul>
</section>
