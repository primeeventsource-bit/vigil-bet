@php
    use App\Support\Clock;

    $marketNames = ['h2h' => 'Moneyline', 'spreads' => 'Spread', 'totals' => 'Total'];
@endphp

<x-layouts.app :title="$event->awayTeam?->display_name.' at '.$event->homeTeam?->display_name">
    <nav class="text-xs">
        <a href="{{ route('odds') }}" class="text-[var(--vg-slate)] underline hover:text-[var(--vg-paper)]">
            &larr; Odds comparison
        </a>
    </nav>

    <header class="mt-3">
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">
            {{ $event->awayTeam?->display_name ?? 'Away' }} at {{ $event->homeTeam?->display_name ?? 'Home' }}
        </h1>
        <p class="mt-1 font-[family-name:var(--font-mono)] text-xs uppercase tracking-wide text-[var(--vg-slate)]">
            {{ $event->sport->value ?? $event->sport }}
            &middot; {{ Clock::format($event->starts_at, 'D j M, g:i A') }}
        </p>
    </header>

    @if ($markets === [])
        <section class="panel mt-5 p-8 text-center">
            <p class="font-semibold">No current prices for this game.</p>
            <p class="mt-1 text-sm text-[var(--vg-muted)]">
                Nothing has been quoted inside the last {{ $freshHours }} hours. An older price
                is not an offer, so none is shown.
            </p>
        </section>
    @else
        @foreach ($markets as $market => $selections)
            <section class="panel mt-5 overflow-hidden">
                <div class="px-5 pt-5">
                    <h2 class="font-[family-name:var(--font-display)] font-semibold">
                        {{ $marketNames[$market] ?? ucfirst($market) }}
                    </h2>
                </div>

                <div class="mt-4 w-full overflow-x-auto">
                    <table class="w-full min-w-[520px] text-left text-sm">
                        <caption class="sr-only">{{ $marketNames[$market] ?? $market }} prices by book</caption>
                        <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                            <tr class="border-b border-[var(--vg-line)]">
                                <th scope="col" class="px-5 py-2.5 font-medium">Selection</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Book</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Pulled</th>
                                <th scope="col" class="px-5 py-2.5 text-right font-medium">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($selections as $key => $quotes)
                                @php([$side, $line] = explode('|', $key))
                                @foreach (collect($quotes)->sortByDesc('price_american') as $index => $quote)
                                    @php($isBest = (int) $quote->price_american === (int) ($best[$market.'|'.$key] ?? 0))
                                    <tr class="border-b border-[var(--vg-line)] last:border-0">
                                        <td class="px-5 py-2.5 text-xs">
                                            @if ($index === 0 || $loop->first)
                                                <span class="font-medium text-[var(--vg-paper)]">
                                                    {{ ucfirst($side) }}@if ($line !== '') {{ ' '.$line }} @endif
                                                </span>
                                            @else
                                                <span class="sr-only">
                                                    {{ ucfirst($side) }}@if ($line !== '') {{ ' '.$line }} @endif
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-xs text-[var(--vg-slate)]">{{ $quote->book_title }}</td>
                                        <td class="px-3 py-2.5 text-[11px] text-[var(--vg-muted)]">
                                            {{ Clock::format($quote->pulled_at, 'H:i') }}
                                        </td>
                                        <td class="px-5 py-2.5 text-right">
                                            <span class="font-[family-name:var(--font-mono)] text-sm font-bold">
                                                {{ $quote->price_american > 0 ? '+' : '' }}{{ $quote->price_american }}
                                            </span>
                                            @if ($isBest)
                                                {{-- A word as well as a colour. --}}
                                                <span class="badge badge-edge ml-2">best</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    @endif

    <p class="mt-4 text-[11px] text-[var(--vg-muted)]">
        Each book's most recent quote inside {{ $freshHours }} hours. Showing one book's stale
        number beside another's current one would read as an opportunity that is not there.
        Not a recommendation.
    </p>
</x-layouts.app>
