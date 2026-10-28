@props(['pick', 'read' => null, 'locked' => false])

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
        {{--
            Confidence and evidence, side by side and never merged.

            The model's stated probability is one claim; how much graded
            history stands behind the market that produced it is a
            different one. A 72% from a market with ten graded
            predictions and a 72% from one with four thousand look
            identical until the second number is printed, which is why
            it is printed.
        --}}
        <span class="text-xs leading-tight text-[var(--vg-slate)]">
            Confidence
            @if (($sample = \App\Models\VlModel::sampleFor($pick->sport, $pick->market)) !== null)
                <span class="block font-[family-name:var(--font-mono)] text-[10px] text-[var(--vg-muted)]" data-numeric>
                    from {{ number_format($sample) }} graded
                </span>
            @endif
        </span>

        <span class="ml-auto h-1.5 w-20 overflow-hidden rounded-full bg-[var(--vg-line)]">
            @if ($pick->model_prob !== null)
                <span class="block h-full rounded-full bg-[var(--vg-chart-fill)]"
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
            @if ($pick->edge_at_price !== null && Gate::allows('board.full'))
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

            {{--
                The market's actual verdict, not a fixed sentence.

                This line used to read "Market verdict: proven against
                the closing line" with a green tick, on every card,
                hardcoded. That was true while `proven` was the only
                tier allowed to publish. It stopped being true the
                moment the other tiers opened, and would have put the
                strongest claim a sports model can make - we beat the
                no-vig close - onto picks from markets that have beaten
                nothing and, in the insufficient tier, have not yet been
                measured at all.

                So it reads the verdict off the row, and the tick is
                green only where it was earned. The specification asks
                for unvalidated predictions to be clearly distinguished
                from those with historical evidence; this is where a
                customer meets that distinction, one line under the
                confidence figure.
            --}}
            @php
                $verdict = \App\Enums\Verdict::tryFrom((string) $pick->market_verdict);
                $earned = $verdict?->claimsEdge() ?? false;
            @endphp

            <li class="flex gap-2">
                <span @class([
                    'text-[var(--vg-edge)]' => $earned,
                    'text-[var(--vg-muted)]' => ! $earned,
                ]) aria-hidden="true">{{ $earned ? '✓' : '·' }}</span>
                <span>
                    Market verdict: {{ $verdict?->label() ?? 'Unknown' }}.
                    <span class="text-[var(--vg-muted)]">{{ $verdict?->description() }}</span>
                </span>
            </li>

            @if ($pick->reason)
                <li class="flex gap-2">
                    <span class="text-[var(--vg-edge)]" aria-hidden="true">&check;</span>
                    <span>{{ $pick->reason }}</span>
                </li>
            @endif
        </ul>
    </div>

    <x-vigil-read :read="$read ?? null" :locked="$locked" />

    <form method="POST" action="{{ route('bets.store') }}" class="mt-4">
        @csrf
        <input type="hidden" name="board_entry_id" value="{{ $pick->id }}">
        <button type="submit"
                class="w-full rounded-lg bg-[var(--vg-green)] px-4 py-2.5 font-bold text-[var(--vg-night)]">
            Add to Bet Slip
        </button>
    </form>
</li>
