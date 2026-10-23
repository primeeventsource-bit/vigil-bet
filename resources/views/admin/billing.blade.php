<x-layouts.admin title="Billing">
    @if ($unmatched > 0)
        {{--
            Events with no customer attached: a processor billing somebody
            we cannot identify. Surfaced rather than buried, because it is
            the first sign of a webhook pointed at the wrong environment.
        --}}
        <p class="mt-4 rounded-lg border border-[var(--vg-loss)] px-4 py-3 text-sm">
            <strong data-numeric>{{ $unmatched }}</strong>
            {{ Str::plural('event', $unmatched) }} matched no account. That usually means a
            webhook from another environment is pointed here.
        </p>
    @endif

    <form method="GET" action="{{ route('admin.billing') }}" class="mt-5 flex flex-wrap items-end gap-3">
        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Type</span>
            <select name="type" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <option value="">All</option>
                @foreach ($types as $available)
                    <option value="{{ $available }}" @selected($type === $available)>{{ $available }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm font-semibold">
            Filter
        </button>
    </form>

    <section class="panel mt-5 overflow-hidden">
        @if ($events->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-[var(--vg-slate)]">
                No billing events. Nothing is on sale yet.
            </p>
        @else
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[800px] text-left text-sm">
                    <caption class="sr-only">Billing events</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">When</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Type</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Account</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Processor</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ $event->occurred_at->toDayDateTimeString() }}
                                </td>
                                <td class="px-3 py-3 font-[family-name:var(--font-mono)] text-xs">
                                    {{ $event->type }}
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    @if ($event->user)
                                        <a class="underline" href="{{ route('admin.user', $event->user) }}">
                                            {{ $event->user->email }}
                                        </a>
                                    @else
                                        <span class="text-[var(--vg-loss)]">unmatched</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ $event->driver }}
                                    @if ($event->fromGateway())
                                        <span class="block">{{ Str::limit($event->gateway_event_id, 18) }}</span>
                                    @else
                                        <span class="block">raised here</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ $event->amount_cents === null
                                        ? '—'
                                        : '$'.number_format($event->amount_cents / 100, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4">{{ $events->links() }}</div>
        @endif
    </section>

    <p class="mt-4 text-xs text-[var(--vg-slate)]">
        Append-only. Refunds are issued from a customer's own page, against the charge they
        belong to.
    </p>
</x-layouts.admin>
