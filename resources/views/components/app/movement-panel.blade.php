@props(['movement'])

<div class="panel min-w-0 p-5">
    <div class="flex items-center justify-between">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Live Odds Movement</h2>
    </div>

    @if ($movement->isNotEmpty())
        <ul class="mt-4 space-y-3">
            @foreach ($movement as $move)
                <li class="flex items-center justify-between gap-3 text-sm">
                    <span class="min-w-0 truncate">
                        {{ $move['matchup'] ?: strtoupper($move['market'] ?? '') }}
                        @if ($move['line'] !== null)
                            <span class="text-[var(--vg-slate)]" data-numeric>{{ $move['line'] }}</span>
                        @endif
                    </span>

                    <span class="flex shrink-0 items-center gap-2 font-[family-name:var(--font-mono)] text-xs tabular-nums" data-numeric>
                        <span class="text-[var(--vg-slate)]">
                            {{ $move['from'] > 0 ? '+' : '' }}{{ $move['from'] }}
                            &rarr;
                            {{ $move['to'] > 0 ? '+' : '' }}{{ $move['to'] }}
                        </span>

                        {{-- Arrow and a word, never colour alone. --}}
                        <span @class([
                            'inline-flex items-center gap-0.5',
                            'text-[var(--vg-edge)]' => $move['direction'] === 'up',
                            'text-[var(--vg-loss)]' => $move['direction'] === 'down',
                        ])>
                            <x-app.icon :name="$move['direction']" class="h-3.5 w-3.5" />
                            <span class="sr-only">{{ $move['direction'] === 'up' ? 'lengthened' : 'shortened' }}</span>
                        </span>
                    </span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-4 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-8 text-center text-xs text-[var(--vg-slate)]">
            No price history yet. Prices are pulled one game at a time, never in bulk.
        </p>
    @endif
</div>
