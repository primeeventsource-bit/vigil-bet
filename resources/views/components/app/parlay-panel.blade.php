<div class="panel min-w-0 p-5">
    <div class="flex items-center justify-between">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Parlay Builder</h2>
        <x-app.icon name="chevron" class="h-4 w-4 text-[var(--vg-slate)]" />
    </div>

    {{--
        A slip with nothing on it, because a slip is built from board
        entries and there are none to add yet. The estimates below stay
        blank for the same reason: Edge Lab's parlay judge produces them
        and it has not been asked anything.
    --}}
    <div class="mt-4 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-8 text-center text-xs text-[var(--vg-slate)]">
        No selections yet
    </div>

    <a href="{{ route('parlays') }}"
       class="mt-3 flex w-full items-center justify-center gap-1.5 rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm text-[var(--vg-slate)] hover:text-[var(--vg-paper)]">
        <x-app.icon name="plus" class="h-3.5 w-3.5" />
        Add Selection
    </a>

    <div class="mt-3 flex items-center gap-3">
        <a href="{{ route('parlays') }}"
           class="rounded-lg bg-[var(--vg-edge)] px-4 py-2 text-sm font-bold text-[var(--vg-night)]">
            Analyse Parlay
        </a>

        <div class="text-[11px] leading-tight text-[var(--vg-slate)]" data-numeric>
            <p>Est. Win Probability: —</p>
            <p>Est. Payout: —</p>
        </div>
    </div>

    <p class="mt-3 text-[11px] leading-snug text-[var(--vg-slate)]">
        A parlay multiplies the book's margin and the model's error as fast as it multiplies
        the payout. Most are declined.
    </p>
</div>
