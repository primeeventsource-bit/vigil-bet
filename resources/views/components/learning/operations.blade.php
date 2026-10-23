@props(['feeds', 'spend'])

<section class="mt-8 grid gap-4 lg:grid-cols-2">
    <div class="panel min-w-0 p-5">
        <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">Data in</h2>
        <p class="mt-1 text-xs text-[var(--vg-slate)]">
            A feed that stopped answering looks exactly like a quiet night until you can see when
            it last worked.
        </p>

        <ul class="mt-4 space-y-2.5">
            @foreach ($feeds as $feed)
                <li class="flex items-center justify-between gap-3 text-sm">
                    <span class="font-medium">{{ $feed['feed'] }}</span>

                    <span class="flex items-center gap-3 text-xs text-[var(--vg-slate)]">
                        @if ($feed['failures_24h'] > 0)
                            <span class="badge badge-warning" data-numeric>
                                {{ $feed['failures_24h'] }} failed
                            </span>
                        @endif

                        <span data-numeric>
                            {{ $feed['last_success_at']?->diffForHumans() ?? 'never synced' }}
                        </span>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="panel min-w-0 p-5">
        <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">Explanation layer</h2>
        <p class="mt-1 text-xs text-[var(--vg-slate)]">
            What the Reads cost this month, including the calls whose output was rejected — those
            are the expensive ones to be blind to.
        </p>

        <dl class="mt-4 grid grid-cols-3 gap-3 text-sm">
            @foreach ([
                ['Calls', number_format($spend['calls'])],
                ['Rejected', number_format($spend['rejected'])],
                ['Cost', $spend['cost']],
            ] as [$term, $value])
                <div>
                    <dt class="text-xs text-[var(--vg-slate)]">{{ $term }}</dt>
                    <dd class="mt-0.5 font-[family-name:var(--font-mono)] font-semibold tabular-nums" data-numeric>
                        {{ $value }}
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>

{{--
    The controls the spec asks for - pause generation, disable a feed, roll
    back a model version - all act on Edge Lab, and Edge Lab's API is
    read-only by construction. Buttons here would either do nothing or
    require a write endpoint that the whole integration was designed not
    to have. Saying where the control actually lives is more use than a
    control that lies.
--}}
<p class="mt-6 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-3 text-xs leading-relaxed text-[var(--vg-slate)]">
    <span class="font-semibold text-[var(--vg-paper)]">Controls live in the engine.</span>
    Pausing predictions, disabling a source and rolling back a model version are all operations on
    Edge Lab, whose API is read-only by construction — this page reports, it does not steer. Adding
    buttons here would mean a write endpoint the integration was deliberately built without.
</p>
