<x-layouts.marketing :title="$pick->selection.' — published pick'">
    <section class="mx-auto max-w-2xl px-6 py-16">
        <p class="font-[family-name:var(--font-mono)] text-xs uppercase tracking-wide text-[var(--vg-slate)]">
            Published pick &middot; <x-local-time :at="$pick->published_at" format="j F Y, H:i T" />
        </p>

        <h1 class="mt-3 font-[family-name:var(--font-display)] text-3xl font-bold leading-tight">
            {{ $pick->selection }}
        </h1>

        <p class="mt-2 text-sm text-[var(--vg-slate)]">
            {{ $pick->matchup ?? 'Game '.$pick->edge_lab_event_id }}
            &middot; {{ strtoupper($pick->sport) }}
            &middot; {{ $pick->market }}
            @if ($pick->line !== null) &middot; <span data-numeric>{{ $pick->line }}</span> @endif
        </p>

        {{--
            Published before kickoff, and the page shows both timestamps
            so a reader can check rather than take it on trust. That is
            the whole purpose of a permanent URL for a pick.
        --}}
        <div class="panel mt-8 p-5">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-[var(--vg-slate)]">Published</dt>
                    <dd class="mt-0.5 font-[family-name:var(--font-mono)] text-sm tabular-nums" data-numeric>
                        {{ $pick->published_at->toIso8601String() }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--vg-slate)]">Kickoff</dt>
                    <dd class="mt-0.5 font-[family-name:var(--font-mono)] text-sm tabular-nums" data-numeric>
                        {{ $pick->kickoff_at->toIso8601String() }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--vg-slate)]">Price taken</dt>
                    <dd class="mt-0.5 font-[family-name:var(--font-mono)] text-lg font-bold tabular-nums" data-numeric>
                        {{ $pick->priceWritten() }}
                        <span class="text-xs font-normal text-[var(--vg-slate)]">
                            at {{ $pick->book_title ?? $pick->book ?? 'an unnamed book' }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--vg-slate)]">Model probability</dt>
                    <dd class="mt-0.5 font-[family-name:var(--font-mono)] text-lg font-bold tabular-nums" data-numeric>
                        {{ $pick->model_prob === null ? '—' : round($pick->model_prob * 100, 1).'%' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--vg-slate)]">Market fair probability</dt>
                    <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                        {{ $pick->fair_prob === null ? '—' : round($pick->fair_prob * 100, 1).'%' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--vg-slate)]">Edge at this price</dt>
                    <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                        {{ $pick->edge_at_price === null ? '—' : sprintf('%+.2f%%', $pick->edge_at_price * 100) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--vg-slate)]">Suggested stake</dt>
                    <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                        {{ $pick->stake_fraction === null
                            ? '—'
                            : round($pick->stake_fraction * 100, 2).'% of bankroll' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--vg-slate)]">Model version</dt>
                    <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                        {{ $pick->model_version === null ? '—' : 'v'.$pick->model_version }}
                    </dd>
                </div>
            </dl>
        </div>

        {{-- The result, or the honest absence of one. --}}
        <div class="panel mt-5 p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Result</h2>

            @if ($pick->result === null)
                <p class="mt-2 text-sm text-[var(--vg-slate)]">
                    Not settled yet. This pick is graded automatically from the final score once
                    {{ config('brand.name') }} has it — nothing here is marked by hand.
                </p>
            @else
                <div class="mt-3 flex flex-wrap items-baseline gap-6">
                    <span @class([
                        'badge',
                        'badge-edge' => $pick->result->status === 'won',
                        'badge-pass' => in_array($pick->result->status, ['push', 'void'], true),
                    ])>{{ ucfirst($pick->result->status) }}</span>

                    <span class="font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums" data-numeric>
                        {{ sprintf('%+.2f', $pick->result->units) }}
                        <span class="text-xs font-normal text-[var(--vg-slate)]">units</span>
                    </span>

                    @if ($pick->result->final_home_score !== null)
                        <span class="font-[family-name:var(--font-mono)] text-sm tabular-nums text-[var(--vg-slate)]" data-numeric>
                            Final {{ $pick->result->final_home_score }}&ndash;{{ $pick->result->final_away_score }}
                        </span>
                    @endif
                </div>

                <dl class="mt-4 grid gap-4 border-t border-[var(--vg-line)] pt-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-[var(--vg-slate)]">Closing price</dt>
                        <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                            {{ $pick->result->closing_price_american === null
                                ? '—'
                                : ($pick->result->closing_price_american > 0 ? '+' : '').$pick->result->closing_price_american }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[var(--vg-slate)]">Closing line value</dt>
                        <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                            {{ $pick->result->clv === null ? '—' : sprintf('%+.2f%%', $pick->result->clv * 100) }}
                        </dd>
                    </div>
                </dl>

                @if ($pick->result->clv !== null)
                    <p class="mt-3 text-xs leading-relaxed text-[var(--vg-slate)]">
                        Closing line value compares the price taken against the last price quoted
                        before kickoff. A positive figure means the market moved toward this pick —
                        which is evidence independent of whether it won.
                    </p>
                @endif
            @endif
        </div>

        <p class="mt-6 text-xs text-[var(--vg-slate)]">
            This page is permanent and its figures cannot be edited. The row was written before
            kickoff and the database rejects any update to it.
        </p>

        <p class="mt-6">
            <a class="text-[var(--vg-blue)] underline" href="{{ route('record') }}">
                &larr; The whole record
            </a>
        </p>
    </section>
</x-layouts.marketing>
