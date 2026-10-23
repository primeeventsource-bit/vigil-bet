<x-layouts.admin title="Picks ledger">
    <section class="mt-5 grid gap-4 sm:grid-cols-3">
        <x-stat-tile label="Published" :value="$published ?: null"
                     :sample="$published === 0 ? 'nothing has cleared the gate' : 'before kickoff, always'" />
        <x-stat-tile label="Awaiting a result" :value="$pending ?: null"
                     sample="graded from the final score" />
        <x-stat-tile label="Settled" :value="($published - $pending) ?: null"
                     sample="in the public record" />
    </section>

    <section class="panel mt-5 overflow-hidden">
        @if ($picks->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-[var(--vg-slate)]">
                Nothing published. No market has cleared the gate, so nothing has been sold from
                and nothing has been claimed.
            </p>
        @else
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-sm">
                    <caption class="sr-only">Published picks and results</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Published</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Kickoff</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Pick</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Price</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Result</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Units</th>
                            <th scope="col" class="px-5 py-2.5 font-medium">Model</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($picks as $pick)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3 text-xs text-[var(--vg-slate)]">
                                    <a class="underline" href="{{ route('record.pick', $pick) }}">
                                        {{ $pick->published_at->toDayDateTimeString() }}
                                    </a>
                                </td>
                                <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ $pick->kickoff_at->toDayDateTimeString() }}
                                </td>
                                <td class="px-3 py-3">
                                    <span class="font-medium">{{ $pick->selection }}</span>
                                    <span class="block text-xs text-[var(--vg-slate)]">
                                        {{ $pick->sport }} &middot; {{ $pick->market }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ $pick->priceWritten() }}
                                </td>
                                <td class="px-3 py-3">
                                    @if ($pick->result === null)
                                        <span class="badge">Running</span>
                                    @else
                                        <span @class([
                                            'badge',
                                            'badge-edge' => $pick->result->status === 'won',
                                            'badge-pass' => in_array($pick->result->status, ['push', 'void'], true),
                                        ])>{{ ucfirst($pick->result->status) }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ $pick->result === null ? '—' : sprintf('%+.2f', $pick->result->units) }}
                                </td>
                                <td class="px-5 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ $pick->model_version === null ? '—' : 'v'.$pick->model_version }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4">{{ $picks->links() }}</div>
        @endif
    </section>

    <p class="mt-4 max-w-2xl text-xs leading-relaxed text-[var(--vg-slate)]">
        Append-only, with a database constraint refusing any row published at or after kickoff.
        There is deliberately no action on this page: a ledger an operator can edit is not a
        ledger.
    </p>
</x-layouts.admin>
