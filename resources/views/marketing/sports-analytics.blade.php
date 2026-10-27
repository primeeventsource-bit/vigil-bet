<x-layouts.marketing title="Sports analytics">
    <section class="border-b border-[var(--vg-line)]">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            <h1 class="font-[family-name:var(--font-display)] text-4xl font-bold sm:text-5xl">
                Sports analytics
            </h1>
            <p class="mt-5 max-w-2xl text-lg text-[var(--vg-slate)]">
                What is covered, what has been proved, and what has not.
            </p>
        </div>
    </section>

    <section class="border-b border-[var(--vg-line)]">
        <div class="mx-auto max-w-5xl px-6 py-14">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-stat-tile label="Leagues covered"
                             :value="(string) count($sports)"
                             :sample="$sports === [] ? 'none enabled' : strtoupper(implode(', ', $sports))" />

                <x-stat-tile label="Markets modelled"
                             :value="$total ? (string) $total : null"
                             sample="a model per sport and market" />

                {{--
                    The number that matters, and the one a brochure
                    would leave out. A market is modelled long before it
                    is trusted, and only the second number decides
                    whether a customer ever sees anything from it.
                --}}
                <x-stat-tile label="Cleared the gate"
                             :value="$proven ? (string) $proven : null"
                             :tone="$proven > 0 ? 'edge' : 'ink'"
                             :sample="$proven === 0 ? 'none yet' : 'publishing picks'" />
            </div>
        </div>
    </section>

    <section class="border-b border-[var(--vg-line)] bg-[var(--vg-navy)]">
        <div class="mx-auto max-w-5xl px-6 py-14">
            <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">What the gate asks</h2>
            <div class="mt-5 max-w-2xl space-y-4 text-[var(--vg-slate)]">
                <p>
                    A model is not allowed to publish because it looks accurate. It is allowed
                    to publish when its confidence has been measured against real results and
                    found to be honest &mdash; when the things it calls 60% happen about 60% of
                    the time, over a sample large enough to mean something.
                </p>
                <p>
                    A model that beats the market but misstates its own confidence is refused,
                    and so is one with too few predictions to have been measured at all. Most
                    markets, most of the time, are in one of those two states.
                </p>
            </div>
        </div>
    </section>

    @if ($models->isEmpty())
        <section class="border-b border-[var(--vg-line)]">
            <div class="mx-auto max-w-5xl px-6 py-14">
                <p class="text-lg font-semibold">No models have been trained yet.</p>
                <p class="mt-2 max-w-2xl text-[var(--vg-slate)]">
                    Nothing is published until one has, which is why there may be nothing on the
                    picks page today.
                </p>
            </div>
        </section>
    @else
        <section class="border-b border-[var(--vg-line)]">
            <div class="mx-auto max-w-5xl px-6 py-14">
                <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">Model by model</h2>

                @foreach ($models as $sport => $rows)
                    <div class="panel mt-6 overflow-hidden">
                        <h3 class="px-5 pt-5 font-[family-name:var(--font-mono)] text-sm font-bold uppercase tracking-wide">
                            {{ $sport }}
                        </h3>

                        <div class="mt-3 w-full overflow-x-auto">
                            <table class="w-full min-w-[520px] text-left text-sm">
                                <caption class="sr-only">{{ $sport }} models and their verdicts</caption>
                                <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                                    <tr class="border-b border-[var(--vg-line)]">
                                        <th scope="col" class="px-5 py-2.5 font-medium">Market</th>
                                        <th scope="col" class="px-3 py-2.5 font-medium">Verdict</th>
                                        <th scope="col" class="px-3 py-2.5 text-right font-medium">Predictions</th>
                                        <th scope="col" class="px-5 py-2.5 text-right font-medium">Publishing</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $model)
                                        <tr class="border-b border-[var(--vg-line)] last:border-0">
                                            <td class="px-5 py-2.5 font-[family-name:var(--font-mono)] text-xs">
                                                {{ $model->market }}
                                            </td>
                                            <td class="px-3 py-2.5 text-xs text-[var(--vg-slate)]">
                                                {{ $model->verdictLabel() }}
                                            </td>
                                            <td class="px-3 py-2.5 text-right font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]">
                                                {{ $model->predictions ? number_format($model->predictions) : '—' }}
                                            </td>
                                            <td class="px-5 py-2.5 text-right text-xs">
                                                {{-- A word, not only a colour. --}}
                                                @if ($model->allows_picks)
                                                    <span class="badge badge-edge">Yes</span>
                                                @else
                                                    <span class="text-[var(--vg-muted)]">No</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section>
        <div class="mx-auto max-w-5xl px-6 py-14">
            @if ($stale)
                {{--
                    Said here as well as in the app. A coverage page that
                    looks confident while the feed behind it has stalled
                    is the page most likely to be believed.
                --}}
                <p class="mb-5 text-sm text-[var(--vg-warning)]">
                    A data feed is currently delayed, so some figures above may be behind.
                </p>
            @endif

            <a href="{{ route('opinions') }}"
               class="rounded-lg border border-[var(--vg-line)] px-6 py-3 font-semibold text-[var(--vg-paper)]">
                See what has been published
            </a>
        </div>
    </section>
</x-layouts.marketing>
