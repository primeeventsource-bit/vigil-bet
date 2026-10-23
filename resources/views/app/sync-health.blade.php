<x-layouts.app title="Sync health">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Sync health</h1>

    <p class="mt-2 text-sm text-[var(--vg-slate)]">
        How current this copy of Edge Lab is. A feed that stopped answering looks exactly
        like a quiet night from the board; this is where the difference shows.
    </p>

    @unless ($configured)
        <p role="status" class="mt-6 rounded-md border border-[var(--vg-warning)] px-4 py-3 text-sm">
            <span class="badge badge-warning">Not connected</span>
            <span class="ml-2">EDGE_LAB_TOKEN is not set. Every sync records a skip and spends nothing.</span>
        </p>
    @endunless

    <div class="panel mt-6 overflow-hidden">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Sync status by feed</caption>
            <thead class="border-b border-[var(--vg-line)] text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                <tr>
                    <th scope="col" class="px-4 py-3">Feed</th>
                    <th scope="col" class="px-4 py-3">State</th>
                    <th scope="col" class="px-4 py-3">Last good sync</th>
                    <th scope="col" class="px-4 py-3">Cursor</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($feeds as $feed)
                    <tr class="border-b border-[var(--vg-line)] last:border-0">
                        <th scope="row" class="px-4 py-3 font-medium">{{ $feed['feed'] }}</th>
                        <td class="px-4 py-3">
                            {{-- A word as well as a colour, always. --}}
                            @if ($feed['never_synced'])
                                <span class="badge badge-warning">Never synced</span>
                            @elseif ($feed['is_stale'])
                                <span class="badge badge-warning">Delayed</span>
                            @else
                                <span class="badge badge-edge">Current</span>
                            @endif

                            @if ($feed['last_status'] === 'failed')
                                <span class="ml-1 badge badge-pass">Last attempt failed</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-[var(--vg-slate)]" data-numeric>
                            @if ($feed['last_success_at'])
                                {{ $feed['age_minutes'] }} min ago
                                <span class="text-xs">(stale after {{ $feed['stale_after_minutes'] }})</span>
                            @else
                                never
                            @endif
                        </td>
                        <td class="px-4 py-3 text-[var(--vg-slate)]" data-numeric>{{ $feed['cursor'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="panel mt-6 p-5">
        <h2 class="font-semibold">Circuit breaker</h2>
        <p class="mt-1 text-sm text-[var(--vg-slate)]">
            Stops one Edge Lab outage becoming two by spending its rate limit on failures.
        </p>
        <p class="mt-3 text-sm">
            State:
            <strong>{{ $breaker['state'] }}</strong>,
            {{ $breaker['consecutive_failures'] }} consecutive failure(s)
            @if ($breaker['retry_in_seconds'])
                , retrying in about {{ $breaker['retry_in_seconds'] }}s
            @endif
        </p>
    </div>

    <h2 class="mt-8 font-[family-name:var(--font-display)] text-lg font-semibold">Recent runs</h2>

    <div class="panel mt-3 overflow-hidden">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">The last 25 sync attempts</caption>
            <thead class="border-b border-[var(--vg-line)] text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                <tr>
                    <th scope="col" class="px-4 py-2">Started</th>
                    <th scope="col" class="px-4 py-2">Feed</th>
                    <th scope="col" class="px-4 py-2">Result</th>
                    <th scope="col" class="px-4 py-2">Fetched</th>
                    <th scope="col" class="px-4 py-2">New</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recent as $run)
                    <tr class="border-b border-[var(--vg-line)] last:border-0">
                        <td class="px-4 py-2 text-[var(--vg-slate)]" data-numeric>
                            {{ $run->started_at?->diffForHumans() }}
                        </td>
                        <td class="px-4 py-2">{{ $run->feed }}</td>
                        <td class="px-4 py-2">
                            <span @class([
                                'badge',
                                'badge-edge' => $run->status === 'success',
                                'badge-pass' => $run->status === 'skipped',
                                'badge-warning' => $run->status === 'failed',
                            ])>{{ $run->status }}</span>
                            @if ($run->message)
                                <span class="ml-2 text-xs text-[var(--vg-slate)]">{{ $run->message }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2" data-numeric>{{ $run->records_fetched }}</td>
                        <td class="px-4 py-2" data-numeric>{{ $run->records_written }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-4 text-[var(--vg-slate)]">
                            Nothing has run yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
