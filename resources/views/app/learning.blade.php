<x-layouts.app title="AI Learning Center">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">AI Learning Center</h1>
            <p class="mt-1 text-sm text-[var(--vg-slate)]">
                Whether the engine is getting better, measured against the closing line.
            </p>
        </div>
    </div>

    {{-- Headline. Every figure read from stored model versions. --}}
    <section class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-tile label="Model versions tracked" :value="$headline['versions']" />

        <x-stat-tile
            label="Markets proven"
            :value="$headline['proven'].' of '.$headline['markets']"
            :tone="$headline['proven'] > 0 ? 'edge' : 'ink'"
            sample="only a proven market may sell" />

        <x-stat-tile
            label="Predictions measured"
            :value="number_format($headline['predictions_measured'])"
            sample="newest version per market" />

        <x-stat-tile
            label="Last trained"
            :value="$headline['last_trained_at']?->diffForHumans() ?? '—'" />
    </section>

    @if ($headline['proven'] === 0)
        {{--
            The honest headline. Stated once, at the top, rather than left
            for a reader to assemble from fourteen red badges.
        --}}
        <p role="status" class="mt-5 rounded-lg border border-[var(--vg-warning)] bg-[color-mix(in_srgb,var(--vg-warning)_8%,transparent)] px-4 py-3 text-sm">
            <span class="badge badge-warning">Nothing proven</span>
            <span class="ml-2 text-[var(--vg-paper)]">
                No market has beaten the closing line by enough, often enough, to be staked. The
                engine is refusing to promote models that do not clear the gate — which is the
                gate working, not a fault in it. Nothing is being sold.
            </span>
        </p>
    @endif

    {{-- The question the page exists for. --}}
    <h2 class="mt-8 font-[family-name:var(--font-display)] text-lg font-semibold">Is it improving?</h2>
    <p class="mt-1 text-sm text-[var(--vg-slate)]">
        Each market, version by version. A verdict says whether it may sell today; the trajectory
        says whether the work is going anywhere.
    </p>

    <div class="mt-4 grid gap-4 xl:grid-cols-2">
        @foreach ($trajectories as $t)
            <article class="panel min-w-0 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-[family-name:var(--font-display)] font-semibold">
                            {{ $t['sport'] }} {{ $t['market'] }}
                        </h3>
                        <p class="mt-0.5 text-xs text-[var(--vg-slate)]" data-numeric>
                            {{ $t['points']->count() }} version(s) ·
                            {{ number_format((int) $t['current']['predictions']) }} predictions measured
                        </p>
                    </div>

                    <div class="flex flex-col items-end gap-1">
                        <span @class([
                            'badge',
                            'badge-edge' => $t['current']['verdict'] === 'proven',
                            'badge-warning' => in_array($t['current']['verdict'], ['unproven', 'insufficient_data'], true),
                            'badge-pass' => $t['current']['verdict'] === 'negative',
                        ])>{{ str_replace('_', ' ', (string) $t['current']['verdict']) }}</span>

                        @if ($t['direction'])
                            {{-- A word, not just an arrow or a colour. --}}
                            <span @class([
                                'text-[11px] font-semibold uppercase tracking-wide',
                                'text-[var(--vg-edge)]' => $t['direction'] === 'improving',
                                'text-[var(--vg-loss)]' => $t['direction'] === 'regressing',
                                'text-[var(--vg-slate)]' => $t['direction'] === 'flat',
                            ])>
                                {{ $t['direction'] === 'improving' ? '↑ improving' : ($t['direction'] === 'regressing' ? '↓ regressing' : '→ flat') }}
                            </span>
                        @endif
                    </div>
                </div>

                <x-chart.trajectory :points="$t['points']" />

                @if ($t['current']['reason'])
                    <p class="mt-4 border-t border-[var(--vg-line)] pt-3 text-xs leading-relaxed text-[var(--vg-slate)]">
                        <span class="font-semibold text-[var(--vg-paper)]">Latest verdict:</span>
                        {{ $t['current']['reason'] }}
                    </p>
                @endif

                {{-- Calibration, where there is a market to compare against. --}}
                @if ($t['current']['calibration_error'] !== null)
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                        <div>
                            <dt class="text-[var(--vg-slate)]">Model calibration error</dt>
                            <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                {{ sprintf('%.4f', $t['current']['calibration_error']) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[var(--vg-slate)]">Market calibration error</dt>
                            <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                {{ $t['current']['market_calibration_error'] === null
                                    ? 'no benchmark'
                                    : sprintf('%.4f', $t['current']['market_calibration_error']) }}
                            </dd>
                        </div>
                    </dl>
                @endif
            </article>
        @endforeach
    </div>

    <x-learning.gate :gate="$gate" />
    <x-learning.board :board="$board" />
    <x-learning.operations :feeds="$feeds" :spend="$spend" />
</x-layouts.app>
