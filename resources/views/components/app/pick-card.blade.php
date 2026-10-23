@props(['pick'])

{{--
    The phone view of a pick. A card rather than a squeezed table: six
    columns of numbers at 390px is either a horizontal scroll nobody
    finds or a column of two-character words, and the design draws a card
    for exactly that reason.
--}}
<li class="rounded-xl border border-[var(--vg-line)] bg-[var(--vg-navy)] p-4">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-[family-name:var(--font-display)] text-lg font-bold">{{ $pick->selection }}</p>
            <p class="mt-0.5 text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                {{ $pick->payload['matchup'] ?? '' }}
                @if ($pick->market)
                    &middot; {{ $pick->market }}
                @endif
            </p>
        </div>

        <span class="shrink-0 rounded-md bg-[var(--vg-slate-dark)] px-2 py-1 font-[family-name:var(--font-mono)] text-sm tabular-nums" data-numeric>
            {{ $pick->best_price_american === null ? '—' : ($pick->best_price_american > 0 ? '+' : '').$pick->best_price_american }}
        </span>
    </div>

    <div class="mt-3 flex items-center gap-3">
        <span class="font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums text-[var(--vg-edge)]" data-numeric>
            {{ $pick->model_prob === null ? '—' : round($pick->model_prob * 100).'%' }}
        </span>
        <span class="text-xs text-[var(--vg-slate)]">Confidence</span>

        <span class="ml-auto h-1.5 w-20 overflow-hidden rounded-full bg-[var(--vg-line)]">
            @if ($pick->model_prob !== null)
                <span class="block h-full rounded-full bg-[var(--vg-edge)]"
                      style="width: {{ round($pick->model_prob * 100, 1) }}%"></span>
            @endif
        </span>
    </div>

    {{--
        Why this pick. Every line is a figure Edge Lab stored, written out
        in words - not a narrative generated to sound convincing. Where
        the engine gave no reason, the card says nothing rather than
        inventing one.
    --}}
    <div class="mt-4 border-t border-[var(--vg-line)] pt-3">
        <p class="text-sm font-semibold">Why this pick?</p>

        <ul class="mt-2 space-y-1.5 text-sm text-[var(--vg-slate)]">
            @if ($pick->edge_at_price !== null)
                <li class="flex gap-2">
                    <span class="text-[var(--vg-edge)]" aria-hidden="true">&check;</span>
                    <span>Model edge: <span data-numeric>{{ sprintf('%+.1f%%', $pick->edge_at_price * 100) }}</span> over the break-even rate</span>
                </li>
            @endif

            @if ($pick->best_book_title)
                <li class="flex gap-2">
                    <span class="text-[var(--vg-edge)]" aria-hidden="true">&check;</span>
                    <span>Priced at {{ $pick->best_book_title }}</span>
                </li>
            @endif

            <li class="flex gap-2">
                <span class="text-[var(--vg-edge)]" aria-hidden="true">&check;</span>
                <span>Market verdict: proven against the closing line</span>
            </li>

            @if ($pick->reason)
                <li class="flex gap-2">
                    <span class="text-[var(--vg-edge)]" aria-hidden="true">&check;</span>
                    <span>{{ $pick->reason }}</span>
                </li>
            @endif
        </ul>
    </div>

    <form method="POST" action="{{ route('bets.store') }}" class="mt-4">
        @csrf
        <input type="hidden" name="board_entry_id" value="{{ $pick->id }}">
        <button type="submit"
                class="w-full rounded-lg bg-[var(--vg-edge)] px-4 py-2.5 font-bold text-[var(--vg-night)]">
            Add to Bet Slip
        </button>
    </form>
</li>
