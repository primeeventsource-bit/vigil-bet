{{--
    The responsible-gambling line is not decoration and is not optional.
    It comes from config so no template can reword it, and it sits in the
    footer of every page, signed-in or not.
--}}
<footer class="mt-16 border-t border-[var(--vg-line)] bg-[var(--vg-navy)]">
    <div class="mx-auto max-w-6xl px-6 py-10">
        <div class="flex flex-col gap-8 sm:flex-row sm:justify-between">
            <div class="max-w-sm">
                <x-brand.lockup size="h-7" />
                <p class="mt-3 text-sm text-[var(--vg-slate)]">{{ config('brand.description') }}</p>
            </div>

            <nav aria-label="Footer" class="grid grid-cols-2 gap-x-10 gap-y-2 text-sm">
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('pricing') }}">Pricing</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('how-it-works') }}">How it works</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('legal.terms') }}">Terms</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('legal.privacy') }}">Privacy</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('legal.responsible-gambling') }}">Responsible gambling</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('legal.disclaimer') }}">Disclaimer</a>
            </nav>
        </div>

        <div class="mt-10 border-t border-[var(--vg-line)] pt-6 text-sm text-[var(--vg-slate)]">
            <p class="font-semibold text-[var(--vg-paper)]">
                {{ config('vigil.responsible_gambling_line') }}
            </p>

            <p class="mt-3">
                {{ config('brand.name') }} sells information and tools. It does not take bets, hold
                funds, or run contests. You are responsible for knowing whether betting is legal
                where you are. {{ config('vigil.minimum_age') }}+ only.
            </p>

            <p class="mt-3">
                &copy; {{ now()->year }} {{ config('brand.company.legal_name') }}.
                @if (filled(config('brand.company.postal_address')))
                    <span class="ml-1">{{ config('brand.company.postal_address') }}</span>
                @endif
            </p>
        </div>
    </div>
</footer>
