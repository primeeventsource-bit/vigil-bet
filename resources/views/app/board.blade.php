<x-layouts.app title="Board">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">The board</h1>
            <p class="mt-1 max-w-2xl text-sm text-[var(--vg-slate)]">
                Every candidate the engine judged, with what it decided and why. Most of them are
                passes — that is the engine working, not a gap in it.
            </p>
        </div>

        <div class="flex gap-4">
            <div>
                <p class="font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums" data-numeric>
                    {{ number_format($counts['total']) }}
                </p>
                <p class="text-xs text-[var(--vg-slate)]">judged</p>
            </div>
            <div>
                <p @class([
                    'font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums',
                    'text-[var(--vg-edge)]' => $counts['picks'] > 0,
                    'text-[var(--vg-slate)]' => $counts['picks'] === 0,
                ]) data-numeric>{{ number_format($counts['picks']) }}</p>
                <p class="text-xs text-[var(--vg-slate)]">sellable picks</p>
            </div>
        </div>
    </div>

    {{-- Filters: sport, date, market, book, picks/passes --}}
    <form method="GET" action="{{ url()->current() }}" class="panel mt-5 flex flex-wrap items-end gap-3 p-4">
        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Sport</span>
            <select name="sport" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <option value="">All</option>
                @foreach ($options['sports'] as $sport)
                    <option value="{{ $sport }}" @selected($filters['sport'] === $sport)>
                        {{ strtoupper($sport) }}
                    </option>
                @endforeach
            </select>
        </label>

        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Date</span>
            <select name="date" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <option value="">Any date</option>
                @foreach ($options['dates'] as $date)
                    <option value="{{ $date }}" @selected($filters['date'] === $date)>
                        {{ \Illuminate\Support\Carbon::parse($date)->format('D j M') }}
                    </option>
                @endforeach
            </select>
            <span class="mt-1 block text-[11px] text-[var(--vg-slate)]">Game date, not when it was judged</span>
        </label>

        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Market</span>
            <select name="market" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <option value="">All</option>
                @foreach ($options['markets'] as $market)
                    <option value="{{ $market }}" @selected($filters['market'] === $market)>{{ $market }}</option>
                @endforeach
            </select>
        </label>

        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Book</span>
            <select name="book" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <option value="">All</option>
                @foreach ($options['books'] as $book)
                    <option value="{{ $book }}" @selected($filters['book'] === $book)>{{ $book }}</option>
                @endforeach
            </select>
        </label>

        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Show</span>
            <select name="show" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <option value="all" @selected($filters['show'] === 'all')>Everything</option>
                <option value="picks" @selected($filters['show'] === 'picks')>Proven picks only</option>
                <option value="passes" @selected($filters['show'] === 'passes')>Passes only</option>
            </select>
        </label>

        <button type="submit" class="rounded-lg bg-[var(--vg-green)] px-4 py-2 text-sm font-bold text-[var(--vg-night)]">
            Filter
        </button>

        @if (array_filter($filters, fn ($v) => $v !== null && $v !== 'all'))
            <a href="{{ url()->current() }}" class="text-sm text-[var(--vg-slate)] underline">Clear</a>
        @endif
    </form>

    {{-- Why nothing is sellable, when nothing is --}}
    @if ($counts['picks'] === 0 && $counts['total'] > 0)
        <section class="panel mt-5 p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">
                Nothing here is sellable, and here is why
            </h2>

            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-[var(--vg-slate)]">
                The engine judged <strong data-numeric>{{ number_format($counts['total']) }}</strong>
                candidates in this view and passed on all of them.
                {{ config('brand.name') }} only sells from a market that has already beaten the
                closing line over a measured sample, and none of these have.
            </p>

            @if ($verdicts->isNotEmpty())
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($verdicts as $sport => $model)
                        <div class="rounded-lg border border-[var(--vg-line)] px-4 py-3">
                            <p class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                                {{ strtoupper($sport) }} &middot; {{ $model->market }}
                            </p>
                            <p @class([
                                'mt-1 text-sm font-semibold',
                                'text-[var(--vg-edge)]' => $model->verdict === 'proven',
                                'text-[var(--vg-loss)]' => $model->verdict === 'negative',
                            ])>{{ $model->verdictLabel() }}</p>
                            <p class="mt-0.5 text-[11px] text-[var(--vg-slate)]" data-numeric>
                                {{ $model->predictions === null
                                    ? 'not yet measured'
                                    : number_format($model->predictions).' predictions measured' }}
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($passReasons->isNotEmpty())
                <h3 class="mt-6 text-sm font-semibold">Why each one was declined</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ($passReasons as $reason => $count)
                        <x-chart.bar-row :label="str_replace('_', ' ', $reason)" :value="$count"
                                         :max="$passReasons->max()" format="count" />
                    @endforeach
                </ul>
            @endif

            <p class="mt-5 text-xs text-[var(--vg-slate)]">
                <a class="text-[var(--vg-link)] underline" href="{{ route('models') }}">
                    The gate each market was measured against
                </a>
            </p>
        </section>
    @endif

    {{-- The board itself --}}
    <section class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-[var(--vg-line)] px-5 py-3.5 font-[family-name:var(--font-display)] font-semibold">
            {{ $filters['show'] === 'picks'
                ? 'Proven picks'
                : ($filters['show'] === 'passes' ? 'Passes' : 'Every candidate') }}
        </h2>

        @if ($entries->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-[var(--vg-slate)]">
                Nothing matches those filters. Try a different sport or date.
            </p>
        @else
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[920px] text-left text-sm">
                    <caption class="sr-only">Board candidates and the verdict on each</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Game</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Selection</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Model</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Fair</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Price</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Edge</th>
                            <th scope="col" class="px-5 py-2.5 font-medium">Verdict</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            @php($event = $events->get($entry->edge_lab_event_id))
                            <tr class="border-b border-[var(--vg-line)] align-top last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium">
                                        {{ $event?->matchup() ?? 'Game '.$entry->edge_lab_event_id }}
                                    </span>
                                    <span class="block text-xs uppercase text-[var(--vg-slate)]">
                                        {{ $entry->sport }} &middot; {{ $entry->market }}
                                        @if ($event?->starts_at)
                                            &middot; <x-local-time :at="$event->starts_at" format="D j M, H:i" />
                                        @endif
                                    </span>
                                </td>
                                <td class="px-3 py-3">
                                    {{ $entry->selection }}
                                    @if ($entry->line !== null)
                                        <span class="block text-xs text-[var(--vg-slate)]" data-numeric>{{ $entry->line }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ $entry->model_prob === null ? '—' : round($entry->model_prob * 100).'%' }}
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums text-[var(--vg-slate)]" data-numeric>
                                    {{ $entry->fair_prob === null ? '—' : round($entry->fair_prob * 100).'%' }}
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ $entry->best_price_american === null
                                        ? '—'
                                        : ($entry->best_price_american > 0 ? '+' : '').$entry->best_price_american }}
                                    @if ($entry->best_book_title)
                                        <span class="block text-[11px] text-[var(--vg-slate)]">{{ $entry->best_book_title }}</span>
                                    @endif
                                </td>
                                <td @class([
                                    'px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums',
                                    'text-[var(--vg-edge)]' => ($entry->edge_at_price ?? 0) > 0,
                                    'text-[var(--vg-loss)]' => ($entry->edge_at_price ?? 0) < 0,
                                ]) data-numeric>
                                    {{ $entry->edge_at_price === null ? '—' : sprintf('%+.1f%%', $entry->edge_at_price * 100) }}
                                </td>
                                <td class="px-5 py-3">
                                    <span @class([
                                        'badge',
                                        'badge-edge' => $entry->isSellablePick(),
                                        'badge-pass' => ! $entry->isSellablePick(),
                                    ])>{{ $entry->isSellablePick() ? 'Pick' : 'Pass' }}</span>

                                    {{--
                                        The reason, on the row. A board of
                                        passes with no reasons is a wall of
                                        "no" - the reason is the product.
                                    --}}
                                    @unless ($entry->isSellablePick())
                                        <span class="mt-1 block max-w-xs text-[11px] leading-snug text-[var(--vg-slate)]">
                                            {{ $entry->pass_reason
                                                ? str_replace('_', ' ', $entry->pass_reason)
                                                : $entry->market_verdict }}
                                        </span>
                                    @endunless

                                    @if ($entry->isSellablePick())
                                        <form method="POST" action="{{ route('bets.store') }}" class="mt-2">
                                            @csrf
                                            <input type="hidden" name="board_entry_id" value="{{ $entry->id }}">
                                            <button type="submit"
                                                    class="rounded-md bg-[var(--vg-green)] px-3 py-1 text-xs font-bold text-[var(--vg-night)]">
                                                Bet
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>

                            @if ($reads->has($entry->id))
                                <tr class="border-b border-[var(--vg-line)]">
                                    <td colspan="7" class="px-5 pb-4">
                                        <x-vigil-read :read="$reads->get($entry->id)" />
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4">{{ $entries->links() }}</div>
        @endif
    </section>
</x-layouts.app>
