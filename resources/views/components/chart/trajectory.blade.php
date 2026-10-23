@props(['points'])

@php
    /*
     * Log loss improvement against the closing line, version by version.
     * Zero is the market: above it the model beats the closing line, below
     * it the model loses to it. That baseline is the whole chart, so it is
     * drawn as a labelled line rather than left to the reader to infer.
     *
     * Diverging data, so it gets a diverging treatment - one hue above the
     * line, another below, with the zero line as the neutral midpoint.
     * Every bar is also labelled with its number, because four bars is too
     * few for a reader to estimate from and the exact value is the point.
     */
    $measured = collect($points)->filter(fn ($p) => $p['improvement'] !== null)->values();
    $span = max($measured->map(fn ($p) => abs((float) $p['improvement']))->max() ?? 0.01, 0.01);
@endphp

@if ($measured->isEmpty())
    <p class="mt-3 rounded-md border border-dashed border-[var(--vg-line)] px-3 py-4 text-center text-xs text-[var(--vg-slate)]">
        Not measured against a closing line yet, so there is nothing to plot.
    </p>
@else
    <figure class="mt-4">
        <figcaption class="sr-only">
            Log loss improvement against the closing line, by model version. Above zero beats the market.
        </figcaption>

        <div class="space-y-2">
            @foreach ($measured as $point)
                @php
                    $value = (float) $point['improvement'];
                    $beatsMarket = $value > 0;
                    $width = min(50, abs($value) / $span * 50);
                @endphp

                <div class="flex items-center gap-2 text-xs">
                    <span class="w-24 shrink-0 truncate text-[var(--vg-slate)]">{{ $point['label'] }}</span>

                    {{-- The track, with zero dead centre. --}}
                    <span class="relative h-4 min-w-0 flex-1 rounded bg-[var(--vg-night)]">
                        <span class="absolute inset-y-0 left-1/2 w-px bg-[var(--vg-line)]"></span>

                        <span @class([
                                'absolute inset-y-0.5 rounded-sm',
                                'bg-[var(--vg-edge)]' => $beatsMarket,
                                'bg-[var(--vg-loss)]' => ! $beatsMarket,
                            ])
                            style="{{ $beatsMarket ? 'left:50%' : 'right:50%' }}; width: {{ round($width, 2) }}%"></span>
                    </span>

                    {{-- Colour is never the only signal: the sign and the word carry it too. --}}
                    <span @class([
                        'w-16 shrink-0 text-right font-[family-name:var(--font-mono)] tabular-nums',
                        'text-[var(--vg-edge)]' => $beatsMarket,
                        'text-[var(--vg-loss)]' => ! $beatsMarket,
                    ]) data-numeric>{{ sprintf('%+.3f', $value) }}</span>
                </div>
            @endforeach
        </div>

        <p class="mt-2 text-[11px] text-[var(--vg-slate)]">
            Above <span class="font-[family-name:var(--font-mono)]">0.000</span> beats the closing line.
            Below it loses to the closing line.
        </p>
    </figure>
@endif
