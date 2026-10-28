@php
    use App\Support\Clock;
@endphp

<x-layouts.app title="Free trial">
    <header>
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">
            Your {{ $hours }}-hour free trial
        </h1>
        <p class="mt-2 max-w-2xl text-sm text-[var(--vg-slate)]">
            Everything {{ config('brand.name') }} Pro includes, for {{ $hours }} hours.
            It starts when you press the button, not when you registered.
        </p>
    </header>

    @if ($active)
        <section class="panel mt-5 p-6">
            <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">Your trial is running.</h2>
            <p class="mt-2 text-sm text-[var(--vg-slate)]">
                It ends {{ Clock::format($expiresAt, 'l j F, g:i A') }}.
            </p>
            <a href="{{ route('today') }}"
               class="mt-5 inline-block rounded-lg bg-[var(--vg-paper)] px-6 py-3 font-bold text-[var(--vg-night)]">
                Go to the dashboard
            </a>
        </section>
    @elseif ($eligible)
        <section class="panel mt-5 p-6">
            <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">What you get</h2>

            <ul role="list" class="mt-4 grid gap-2.5 sm:grid-cols-2">
                @foreach ((array) config('plans.tiers.trial.features') as $feature)
                    <li class="flex gap-2.5 text-sm text-[var(--vg-slate)]">
                        <span aria-hidden="true" class="mt-0.5 text-[var(--vg-muted)]">&middot;</span>
                        <span>{{ str_replace(':brand', config('brand.name'), $feature) }}</span>
                    </li>
                @endforeach
            </ul>

            <form method="POST" action="{{ route('trial.activate') }}" class="mt-6">
                @csrf
                {{-- The same characters as the pricing card, because a
                     button that changes wording between the page that
                     offered it and the page that presses it is two
                     different offers. --}}
                <button type="submit"
                        class="rounded-lg bg-[var(--vg-paper)] px-6 py-3 font-bold tracking-wide text-[var(--vg-night)]">
                    ACTIVATE MY FREE TRIAL
                </button>
            </form>

            {{--
                The same four sentences as the pricing card, because a
                promise that changes wording between the page that made
                it and the page that acts on it is two promises.
            --}}
            <p class="mt-4 max-w-xl text-xs text-[var(--vg-muted)]">
                No credit card required. The trial expires automatically after {{ $hours }} hours.
                Nothing is charged, and it does not turn into a subscription. When it ends your
                account stays open and your bets, picks and parlays stay where they are.
            </p>
        </section>
    @else
        <section class="panel mt-5 p-6">
            <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">
                @switch($reason)
                    @case('spent') Your trial has already run. @break
                    @case('redeemed') A trial has already been used for this address. @break
                    @case('unverified') Confirm your email address first. @break
                    @default A trial is not available on this account.
                @endswitch
            </h2>

            <p class="mt-2 max-w-2xl text-sm text-[var(--vg-slate)]">
                @switch($reason)
                    @case('spent')
                        Your account is still open and nothing has been deleted &mdash; your bets,
                        saved picks and parlays are all where you left them.
                        @break
                    @case('redeemed')
                        {{-- Said plainly rather than vaguely. Somebody who
                             genuinely has not had one needs to know what to
                             write to support about. --}}
                        One trial is available per person. If you believe this is wrong, write to
                        {{ config('brand.company.support_email') }} and somebody will look.
                        @break
                    @case('unverified')
                        We sent a link when you registered. The trial starts once the address is
                        confirmed.
                        @break
                    @default
                        Write to {{ config('brand.company.support_email') }} if you think this is wrong.
                @endswitch
            </p>

            <div class="mt-5 flex flex-wrap gap-3">
                @if ($reason === 'unverified')
                    <a href="{{ route('verification.notice') }}"
                       class="rounded-lg bg-[var(--vg-paper)] px-6 py-3 font-bold text-[var(--vg-night)]">
                        Confirm my email
                    </a>
                @else
                    <a href="{{ route('pricing') }}"
                       class="rounded-lg bg-[var(--vg-paper)] px-6 py-3 font-bold text-[var(--vg-night)]">
                        See the plans
                    </a>
                @endif

                <a href="{{ route('today') }}"
                   class="rounded-lg border border-[var(--vg-line)] px-6 py-3 font-semibold text-[var(--vg-paper)]">
                    Back to the dashboard
                </a>
            </div>
        </section>
    @endif
</x-layouts.app>
