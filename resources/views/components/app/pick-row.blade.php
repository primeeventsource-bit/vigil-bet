@props(['pick'])

<tr class="border-b border-[var(--vg-line)] last:border-0">
    <td class="px-5 py-3">
        <span class="font-medium">{{ $pick->payload['matchup'] ?? $pick->selection }}</span>
        <span class="block text-xs uppercase text-[var(--vg-slate)]">{{ $pick->sport }}</span>
    </td>

    <td class="px-3 py-3 font-medium">{{ $pick->selection }}</td>

    <td class="px-3 py-3">
        <div class="flex items-center gap-2">
            <span class="w-9 font-[family-name:var(--font-mono)] text-xs tabular-nums" data-numeric>
                {{ $pick->model_prob === null ? '—' : round($pick->model_prob * 100).'%' }}
            </span>
            <span class="h-1.5 w-16 overflow-hidden rounded-full bg-[var(--vg-line)]">
                @if ($pick->model_prob !== null)
                    <span class="block h-full rounded-full bg-[var(--vg-chart-fill)]"
                          style="width: {{ round($pick->model_prob * 100, 1) }}%"></span>
                @endif
            </span>
        </div>
    </td>

    <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
        {{ $pick->best_price_american === null ? '—' : ($pick->best_price_american > 0 ? '+' : '').$pick->best_price_american }}
    </td>

    @can('board.full')
        {{--
            The edge figure. Gated here as well as in the header, and in
            the service behind both - a column the markup hides is still
            a column the HTML carries.
        --}}
        <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums text-[var(--vg-edge)]" data-numeric>
            {{ $pick->edge_at_price === null ? '—' : sprintf('%+.1f%%', $pick->edge_at_price * 100) }}
        </td>
    @endcan

    <td class="px-5 py-3 text-right">
        {{--
            Logs the bet. It does not place one: this product has no sportsbook
            and never takes money. The customer places it at their own
            book and this records that they did, so the track record and
            the closing line value have something to measure.
        --}}
        <form method="POST" action="{{ route('bets.store') }}">
            @csrf
            <input type="hidden" name="board_entry_id" value="{{ $pick->id }}">
            <button type="submit"
                    class="rounded-md bg-[var(--vg-green)] px-3 py-1 text-xs font-bold text-[var(--vg-night)]">
                Bet
                <span class="sr-only">— log {{ $pick->selection }} to My Bets</span>
            </button>
        </form>
    </td>
</tr>
