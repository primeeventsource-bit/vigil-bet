<x-layouts.admin title="Audit log">
    <p class="mt-4 max-w-2xl text-sm text-[var(--vg-slate)]">
        Every administrative write, with who did it and why. Append-only: the table refuses
        updates and deletes in the model and in Postgres, so this list cannot be tidied up.
    </p>

    <form method="GET" action="{{ route('admin.audit') }}" class="mt-5 flex flex-wrap items-end gap-3">
        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Action</span>
            <select name="action" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <option value="">All</option>
                @foreach ($actions as $available)
                    <option value="{{ $available }}" @selected($action === $available)>{{ $available }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm font-semibold">
            Filter
        </button>
    </form>

    <section class="panel mt-5 overflow-hidden">
        @if ($events->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-[var(--vg-slate)]">Nothing recorded.</p>
        @else
            <ul class="divide-y divide-[var(--vg-line)]">
                @foreach ($events as $event)
                    <li class="px-5 py-3.5">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <p class="font-[family-name:var(--font-mono)] text-sm">{{ $event->action }}</p>
                            <p class="text-xs text-[var(--vg-slate)]">
                                {{ $event->created_at->toDayDateTimeString() }}
                                @if ($event->ip) &middot; {{ $event->ip }} @endif
                            </p>
                        </div>

                        <p class="mt-1 text-xs text-[var(--vg-slate)]">
                            {{ $event->actor?->name ?? 'the system' }}
                            @if ($event->subject_type)
                                &middot; {{ $event->subject_type }} #{{ $event->subject_id }}
                            @endif
                        </p>

                        @if ($event->reason)
                            <p class="mt-1 text-sm">{{ $event->reason }}</p>
                        @endif

                        @if ($event->before !== null || $event->after !== null)
                            {{--
                                Before and after, verbatim. The reason is
                                what somebody meant; this is what
                                actually changed, and the two sometimes
                                differ.
                            --}}
                            <p class="mt-1.5 font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-slate)]">
                                {{ json_encode($event->before) }} &rarr; {{ json_encode($event->after) }}
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="px-5 py-4">{{ $events->links() }}</div>
        @endif
    </section>
</x-layouts.admin>
