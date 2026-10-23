@props(['record'])

{{--
    The track record, or the honest absence of one.

    With nothing settled this shows no rate at all - not a zero, not a
    placeholder, not a greyed-out "coming soon" number that a reader
    might take for a real one. §7 is explicit about it, and this is the
    page where the temptation to cheat is strongest.
--}}
<section class="relative border-b border-[var(--vg-line)] bg-[var(--vg-navy)]">
    <div class="mx-auto max-w-6xl px-6 py-16">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="font-[family-name:var(--font-display)] text-3xl font-bold">Public record</h2>
                <p class="mt-2 max-w-lg text-sm text-[var(--vg-slate)]">
                    Every published pick is written down before kickoff with its price and its book,
                    and cannot be edited afterwards.
                </p>
            </div>

            <a href="{{ route('record') }}" class="text-sm font-semibold text-[var(--vg-edge)] hover:underline">
                See the record &rarr;
            </a>
        </div>

        @if ($record['settled'] > 0)
            <dl class="mt-8 grid gap-4 sm:grid-cols-4">
                @foreach ([
                    ['Record', $record['record']],
                    ['Units', $record['units']],
                    ['ROI', $record['roi']],
                    ['Closing line value', $record['clv']],
                ] as [$term, $value])
                    <div class="fx-panel p-5">
                        <dt class="text-xs text-[var(--vg-slate)]">{{ $term }}</dt>
                        <dd class="mt-1 font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums" data-numeric>
                            {{ $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-3 font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]" data-numeric>
                From {{ $record['settled'] }} settled picks.
            </p>
        @else
            <div class="fx-panel mt-8 p-8 text-center">
                <p class="badge badge-pass">Nothing published yet</p>
                <p class="mx-auto mt-4 max-w-xl text-[var(--vg-slate)]">
                    No market has passed the gate, so no pick has been published, so there is no
                    record to show. There is no rate on this page because there is nothing behind
                    one — and a number here before there is evidence for it would be the only
                    thing worth distrusting about everything else.
                </p>
            </div>
        @endif
    </div>
</section>
