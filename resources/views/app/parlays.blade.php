<x-layouts.app title="Parlays">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Parlays</h1>
    <p class="mt-1 max-w-2xl text-sm text-[var(--vg-slate)]">
        Build a slip and have the engine judge it. Most parlays are bad value because the
        book's hold compounds with every leg — this page shows you that figure rather than
        warning you about it.
    </p>

    @error('slip')
        <p class="mt-4 rounded-lg border border-[var(--vg-loss)] px-4 py-3 text-sm">{{ $message }}</p>
    @enderror

    <div class="mt-6 grid min-w-0 grid-cols-[minmax(0,1fr)] gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        {{-- The slip --}}
        <section class="panel min-w-0 overflow-hidden">
            <div class="flex items-center justify-between border-b border-[var(--vg-line)] px-5 py-3.5">
                <h2 class="font-[family-name:var(--font-display)] font-semibold">Your slip</h2>
                <span class="text-xs text-[var(--vg-slate)]" data-numeric>
                    {{ $legs->count() }} / {{ $maxLegs }}
                </span>
            </div>

            @if ($legs->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-[var(--vg-slate)]">
                    Nothing on the slip. Add a pick from the list.
                </p>
            @else
                <ul class="divide-y divide-[var(--vg-line)]">
                    @foreach ($legs as $leg)
                        <li class="flex items-start justify-between gap-3 px-5 py-3.5">
                            <div class="min-w-0">
                                <p class="font-medium">{{ $leg->selection }}</p>
                                <p class="mt-0.5 text-xs uppercase text-[var(--vg-slate)]">
                                    {{ $leg->sport }} &middot; {{ $leg->market }}
                                    @if ($leg->best_price_american !== null)
                                        &middot;
                                        <span data-numeric>{{ $leg->best_price_american > 0 ? '+' : '' }}{{ $leg->best_price_american }}</span>
                                    @endif
                                </p>
                            </div>

                            <form method="POST" action="{{ route('parlays.remove') }}">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="board_entry_id" value="{{ $leg->id }}">
                                <button type="submit" class="text-xs text-[var(--vg-slate)] underline hover:text-[var(--vg-paper)]">
                                    Remove
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>

                <div class="flex flex-wrap items-center gap-3 border-t border-[var(--vg-line)] px-5 py-4">
                    <form method="POST" action="{{ route('parlays.grade') }}">
                        @csrf
                        <button type="submit" @disabled($legs->count() < 2)
                                class="rounded-lg bg-[var(--vg-paper)] px-4 py-2.5 font-bold text-[var(--vg-night)] disabled:opacity-40">
                            Grade this slip
                        </button>
                    </form>

                    <form method="POST" action="{{ route('parlays.clear') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-[var(--vg-slate)] underline">Clear</button>
                    </form>

                    @if ($gradeLimit !== null)
                        <p class="text-xs text-[var(--vg-slate)]">
                            <span data-numeric>{{ $gradesToday }}</span> of
                            <span data-numeric>{{ $gradeLimit }}</span> grades used today.
                            <a class="text-[var(--vg-blue)] underline"
                               href="{{ route('upgrade', ['feature' => 'card.daily']) }}">Unlimited on a paid plan</a>.
                        </p>
                    @endif
                </div>
                {{--
                    Saving is deliberately not the same button as grading.
                    Grading asks whether the slip is worth taking; saving
                    says what you did about it, and a page that did both
                    at once would record bets nobody placed.
                --}}
                <details class="border-t border-[var(--vg-line)]">
                    <summary class="cursor-pointer px-5 py-4 text-sm font-semibold">
                        Save &amp; track this parlay
                    </summary>

                    <form method="POST" action="{{ route('parlays.save') }}" class="space-y-4 px-5 pb-5">
                        @csrf

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">Stake</span>
                                <input type="number" name="stake" step="0.01" min="0.01" required
                                       value="{{ old('stake') }}"
                                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-navy)] px-3 py-2"
                                       data-numeric>
                            </label>

                            <label class="block">
                                <span class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">Sportsbook</span>
                                <input type="text" name="book" maxlength="64" value="{{ old('book') }}"
                                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-navy)] px-3 py-2">
                            </label>

                            <label class="block">
                                <span class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                                    Odds the book accepted
                                </span>
                                <input type="number" name="accepted_price" step="1" value="{{ old('accepted_price') }}"
                                       placeholder="e.g. 260"
                                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-navy)] px-3 py-2"
                                       data-numeric>
                                {{--
                                    The payout is worked out from this, not
                                    from the product of the legs. The two
                                    differ by the book's margin, and using
                                    ours would invent money.
                                --}}
                                <span class="mt-1 block text-xs text-[var(--vg-slate)]">
                                    Leave blank and we use the combined price above.
                                </span>
                            </label>

                            <label class="block">
                                <span class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">Ticket reference</span>
                                <input type="text" name="ticket_reference" maxlength="64"
                                       value="{{ old('ticket_reference') }}"
                                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-navy)] px-3 py-2">
                            </label>
                        </div>

                        <div class="space-y-2">
                            <label class="flex items-start gap-2.5 text-sm">
                                <input type="checkbox" name="placed" value="1" class="mt-0.5" @checked(old('placed'))>
                                <span>
                                    I placed this bet.
                                    <span class="block text-xs text-[var(--vg-slate)]">
                                        Until this is ticked it is saved as a draft and counts as nothing.
                                        Ticking it takes the stake off your bankroll.
                                    </span>
                                </span>
                            </label>

                            <label class="flex items-start gap-2.5 text-sm">
                                <input type="checkbox" name="is_paper" value="1" class="mt-0.5" @checked(old('is_paper'))>
                                <span>
                                    Paper bet.
                                    <span class="block text-xs text-[var(--vg-slate)]">
                                        Tracked and graded, kept out of your bankroll entirely.
                                    </span>
                                </span>
                            </label>
                        </div>

                        <button type="submit" @disabled($legs->count() < 2)
                                class="rounded-lg border border-[var(--vg-line)] px-4 py-2.5 text-sm font-semibold disabled:opacity-40">
                            Save to my parlays
                        </button>
                    </form>
                </details>
            @endif
        </section>

        {{-- The verdict --}}
        <section class="min-w-0">
            @if ($grade === null)
                <div class="panel p-5">
                    <h2 class="font-[family-name:var(--font-display)] font-semibold">Verdict</h2>
                    {{--
                        No figures, because nothing has been graded. The
                        alternative - zeros and em dashes in a full panel -
                        reads as "this slip is worthless" rather than
                        "nothing has been asked".
                    --}}
                    <p class="mt-2 text-sm text-[var(--vg-slate)]">
                        Nothing graded yet. Build a slip of two or more legs and the engine will price it.
                    </p>
                </div>
            @elseif (! $grade->wasAnswered())
                <div class="panel p-5">
                    <h2 class="font-[family-name:var(--font-display)] font-semibold">Verdict</h2>
                    {{--
                        Said as a failure to answer, not as a verdict. A
                        blank result shown as "no value" would be a
                        judgement the engine never made.
                    --}}
                    <p class="mt-2 text-sm text-[var(--vg-slate)]">
                        The engine could not be reached, so this slip has not been graded. That is not
                        a verdict on it — nothing was measured. Try again shortly.
                    </p>
                    <p class="mt-3 text-xs text-[var(--vg-slate)]">{{ $grade->unavailable_reason }}</p>
                </div>
            @else
                <div class="panel p-5">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-[family-name:var(--font-display)] font-semibold">Verdict</h2>
                        <span @class(['badge', 'badge-edge' => $grade->isWorthBacking(), 'badge-pass' => ! $grade->isWorthBacking()])>
                            {{ $grade->isWorthBacking() ? 'Edge' : 'Pass' }}
                        </span>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-xs text-[var(--vg-slate)]">All legs land</dt>
                            <dd class="mt-0.5 font-[family-name:var(--font-mono)] text-xl font-bold tabular-nums" data-numeric>
                                {{ $grade->win_probability === null ? '—' : round($grade->win_probability * 100, 1).'%' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-[var(--vg-slate)]">The book keeps</dt>
                            <dd class="mt-0.5 font-[family-name:var(--font-mono)] text-xl font-bold tabular-nums text-[var(--vg-loss)]" data-numeric>
                                {{ $grade->book_hold === null ? '—' : round($grade->book_hold * 100, 1).'%' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-[var(--vg-slate)]">Fair price</dt>
                            <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                {{ $grade->fair_price_american === null ? '—' : ($grade->fair_price_american > 0 ? '+' : '').$grade->fair_price_american }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-[var(--vg-slate)]">Offered</dt>
                            <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                {{ $grade->offered_price_american === null ? '—' : ($grade->offered_price_american > 0 ? '+' : '').$grade->offered_price_american }}
                            </dd>
                        </div>

                        <div class="col-span-2 border-t border-[var(--vg-line)] pt-3">
                            <dt class="text-xs text-[var(--vg-slate)]">
                                Edge, after the model's own uncertainty
                            </dt>
                            <dd @class([
                                'mt-0.5 font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums',
                                'text-[var(--vg-edge)]' => ($grade->shaded_edge ?? 0) > 0,
                                'text-[var(--vg-loss)]' => ($grade->shaded_edge ?? 0) <= 0,
                            ]) data-numeric>
                                {{ $grade->shaded_edge === null ? '—' : sprintf('%+.2f%%', $grade->shaded_edge * 100) }}
                            </dd>
                        </div>
                    </dl>

                    <x-vigil-read :read="$read" />
                </div>
            @endif
        </section>
    </div>

    {{-- Picks you can add --}}
    <section class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-[var(--vg-line)] px-5 py-3.5 font-[family-name:var(--font-display)] font-semibold">
            Add a leg
        </h2>

        @if ($candidates->isEmpty())
            {{--
                Only picks from proven markets can be legs. With nothing
                proven there is nothing to build with, and saying so beats
                offering a list the engine is not allowed to stand behind.
            --}}
            <p class="px-5 py-8 text-center text-sm text-[var(--vg-slate)]">
                No picks from proven markets right now, so there is nothing to build a slip from.
                A parlay of unproven selections would be graded against probabilities nobody may act on.
            </p>
        @else
            <ul class="divide-y divide-[var(--vg-line)]">
                @foreach ($candidates as $candidate)
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="font-medium">{{ $candidate->selection }}</p>
                            <p class="mt-0.5 text-xs uppercase text-[var(--vg-slate)]">
                                {{ $candidate->sport }} &middot; {{ $candidate->market }}
                                @if ($candidate->model_prob !== null)
                                    &middot; <span data-numeric>{{ round($candidate->model_prob * 100) }}%</span>
                                @endif
                            </p>
                        </div>

                        <form method="POST" action="{{ route('parlays.add') }}">
                            @csrf
                            <input type="hidden" name="board_entry_id" value="{{ $candidate->id }}">
                            <button type="submit"
                                    class="rounded-md border border-[var(--vg-line)] px-3 py-1 text-xs font-semibold text-[var(--vg-slate)] hover:text-[var(--vg-paper)]">
                                Add
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
