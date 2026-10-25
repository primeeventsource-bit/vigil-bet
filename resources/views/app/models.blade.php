<x-layouts.app title="Model Lab">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Model Lab</h1>
    <p class="mt-1 max-w-2xl text-sm text-[var(--vg-slate)]">
        Which markets have beaten the closing line, which have not, and the threshold each was
        measured against. The failures are here too — a page that only showed what passed would
        not be worth reading.
    </p>

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-tile label="Markets modelled" :value="$models->count() ?: null"
                     :sample="$models->isEmpty() ? 'nothing synced yet' : 'from Edge Lab'" />

        <x-stat-tile label="Allowed to sell" :value="$sellable ?: null"
                     :sample="$sellable === 0 ? 'none have passed the gate' : 'proven against the close'"
                     :tone="$sellable > 0 ? 'edge' : 'ink'" />

        <x-stat-tile label="Unproven" :value="$byVerdict['unproven'] ?? null"
                     sample="measured, did not pass" />

        <x-stat-tile label="Negative" :value="$byVerdict['negative'] ?? null"
                     sample="measured, lost to the market"
                     :tone="($byVerdict['negative'] ?? 0) > 0 ? 'loss' : 'ink'" />
    </section>

    {{-- The gate --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">The gate</h2>

        @if ($gate === null)
            <p class="mt-2 text-sm text-[var(--vg-slate)]">
                @if ($models->isEmpty())
                    No models have been synced yet, so there is no gate to show.
                @else
                    These models were not all measured against the same thresholds, so there is no
                    single gate to state. Each market's own threshold is in its row below.
                @endif
            </p>
        @else
            <p class="mt-2 text-sm text-[var(--vg-slate)]">
                A market may only produce picks once it has cleared every one of these. Until then
                it can be analysed and not sold.
            </p>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($gate as $key => $value)
                    <div class="rounded-lg border border-[var(--vg-line)] px-4 py-3">
                        <dt class="text-xs text-[var(--vg-slate)]">
                            {{ Str::of($key)->replace('_', ' ')->ucfirst() }}
                        </dt>
                        <dd class="mt-0.5 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                            {{ is_scalar($value) ? $value : json_encode($value) }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    {{-- Every market --}}
    <section class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-[var(--vg-line)] px-5 py-3.5 font-[family-name:var(--font-display)] font-semibold">
            Every market measured
        </h2>

        @if ($models->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-[var(--vg-slate)]">
                No model versions have been synced from Edge Lab yet.
            </p>
        @else
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <caption class="sr-only">Model verdicts by market</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Market</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Version</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Predictions</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Log loss vs market</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Verdict</th>
                            <th scope="col" class="px-5 py-2.5 font-medium">May sell</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($models as $model)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium uppercase">{{ $model->sport }}</span>
                                    <span class="block text-xs text-[var(--vg-slate)]">{{ $model->market }}</span>
                                </td>
                                <td class="px-3 py-3 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    v{{ $model->version }}
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ $model->predictions === null ? '—' : number_format($model->predictions) }}
                                </td>
                                <td @class([
                                    'px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums',
                                    'text-[var(--vg-edge)]' => ($model->log_loss_improvement ?? 0) > 0,
                                    'text-[var(--vg-loss)]' => ($model->log_loss_improvement ?? 0) < 0,
                                ]) data-numeric>
                                    {{ $model->log_loss_improvement === null
                                        ? '—'
                                        : sprintf('%+.4f', $model->log_loss_improvement) }}
                                </td>
                                <td class="px-3 py-3">
                                    <span @class([
                                        'badge',
                                        'badge-edge' => $model->verdict === 'proven',
                                        'badge-pass' => $model->verdict !== 'proven',
                                    ])>{{ $model->verdictLabel() }}</span>
                                    @if ($model->verdict_reason)
                                        <span class="mt-1 block max-w-xs text-xs text-[var(--vg-slate)]">
                                            {{ $model->verdict_reason }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-xs">
                                    {{ $model->allowsPicks() ? 'Yes' : 'No' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Why candidates were declined --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Why candidates were declined</h2>

        @if ($passReasons->isEmpty())
            <p class="mt-2 text-sm text-[var(--vg-slate)]">
                Nothing has been scanned yet, so there is nothing to explain.
            </p>
        @else
            <p class="mt-1 text-xs text-[var(--vg-slate)]">
                The concrete answer to why nothing is being sold: every candidate the engine
                looked at and passed on, grouped by the reason.
            </p>

            <ul class="mt-4 space-y-2">
                @foreach ($passReasons as $reason => $count)
                    <x-chart.bar-row :label="$reason" :value="$count" :max="$passReasons->max()" format="count" />
                @endforeach
            </ul>
        @endif
    </section>

    {{-- The calibration chart §8 asks for, and does not exist yet --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Calibration</h2>

        {{--
            §8 asks for a calibration chart here. Edge Lab stores the bins
            and its internal API does not expose them, so there is nothing
            to plot. Said outright rather than filled with a plausible
            curve - a fabricated calibration chart on the page whose whole
            purpose is transparency would be the single worst thing in
            this product.
        --}}
        <p class="mt-2 text-sm text-[var(--vg-slate)]">
            Not available yet. A calibration curve compares predicted probability against
            observed frequency, bin by bin — Edge Lab computes and stores those bins, but its
            read-only API does not expose them, so there is nothing here to plot.
        </p>
        <p class="mt-2 text-xs text-[var(--vg-slate)]">
            One read-only endpoint (<code>calibration_bins</code>) would finish this panel. Until
            it exists, the verdict and its threshold above are what the gate was actually decided
            on — no curve is drawn from numbers nobody measured.
        </p>
    </section>
</x-layouts.app>
