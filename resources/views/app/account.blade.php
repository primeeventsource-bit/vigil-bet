<x-layouts.app title="Account">
    @php($user = auth()->user())
    @php($subscription = $user->subscription)
    @php($brand = fn (?string $t) => str_replace(':brand', config('brand.name'), (string) $t))

    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Account</h1>

    @if (session('status'))
        <p class="mt-4 rounded-lg border border-[color-mix(in_srgb,var(--vg-edge)_40%,transparent)] bg-[color-mix(in_srgb,var(--vg-edge)_10%,transparent)] px-4 py-3 text-sm text-[var(--vg-paper)]">
            {{ session('status') }}
        </p>
    @endif

    @error('cancel')<p class="mt-4 text-sm text-[var(--vg-loss)]">{{ $message }}</p>@enderror
    @error('resume')<p class="mt-4 text-sm text-[var(--vg-loss)]">{{ $message }}</p>@enderror
    @error('portal')<p class="mt-4 text-sm text-[var(--vg-loss)]">{{ $message }}</p>@enderror
    @error('tier')<p class="mt-4 text-sm text-[var(--vg-loss)]">{{ $message }}</p>@enderror

    {{-- Plan and billing --}}
    <div class="panel mt-6 p-6">
        <h2 class="font-semibold">Plan</h2>

        <p class="mt-1 text-sm text-[var(--vg-slate)]">
            You are on
            <strong class="text-[var(--vg-paper)]">
                {{ $subscription?->describe() ?? $brand(config('plans.tiers.free.name')) }}
            </strong>.
        </p>

        @if ($subscription?->status === 'past_due')
            {{--
                Said plainly, with the date. A customer in dunning who is
                told only "there is a problem with your payment" cannot
                tell whether they have an hour or a fortnight.
            --}}
            <p class="mt-3 rounded-lg border border-[var(--vg-loss)] px-4 py-3 text-sm">
                A payment did not go through.
                @if ($subscription->current_period_end)
                    You keep everything until {{ $subscription->current_period_end->toFormattedDateString() }}.
                @endif
                After that the account moves to the free plan — nothing is deleted.
            </p>
        @endif

        @if ($subscription?->cancel_at_period_end)
            <p class="mt-3 text-sm text-[var(--vg-slate)]">
                This will not renew.
                @if ($subscription->current_period_end)
                    You keep everything until {{ $subscription->current_period_end->toFormattedDateString() }}.
                @endif
            </p>

            <form method="POST" action="{{ route('billing.resume') }}" class="mt-4">
                @csrf
                <button type="submit" class="rounded-lg bg-[var(--vg-edge)] px-4 py-2 text-sm font-bold text-[var(--vg-night)]">
                    Keep my subscription
                </button>
            </form>
        @elseif ($subscription && $subscription->tier !== 'free' && $subscription->gateway_subscription_id)
            {{--
                One button, one POST. §4: cancelling is one click and no
                dark patterns, so there is no confirmation step, no
                retention offer in the way, and nothing is taken away
                today.
            --}}
            <form method="POST" action="{{ route('billing.cancel') }}" class="mt-4">
                @csrf
                <button type="submit" class="rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm font-semibold text-[var(--vg-slate)] hover:text-[var(--vg-paper)]">
                    Cancel subscription
                </button>
            </form>

            <p class="mt-2 text-xs text-[var(--vg-slate)]">
                Takes effect at the end of the period you have paid for. You keep everything until then.
            </p>
        @endif
    </div>

    {{-- Subscribing --}}
    @if ($gatewayConfigured)
        <div class="panel mt-5 p-6">
            <h2 class="font-semibold">
                {{ $subscription?->gateway_subscription_id ? 'Change plan' : 'Subscribe' }}
            </h2>

            @error('payment_token')<p class="mt-2 text-sm text-[var(--vg-loss)]">{{ $message }}</p>@enderror

            <form method="POST"
                  action="{{ $subscription?->gateway_subscription_id ? route('billing.plan') : route('billing.subscribe') }}"
                  class="mt-4 space-y-4">
                @csrf

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($sellableTiers as $key => $tier)
                        <label class="flex cursor-pointer gap-3 rounded-xl border border-[var(--vg-line)] p-4 has-checked:border-[var(--vg-edge)]">
                            <input type="radio" name="tier" value="{{ $key }}" class="mt-1 accent-[var(--vg-edge)]"
                                   @checked($subscription?->tier === $key)>
                            <span>
                                <span class="block font-semibold">{{ $brand($tier['name']) }}</span>
                                <span class="block font-[family-name:var(--font-mono)] text-sm tabular-nums text-[var(--vg-slate)]" data-numeric>
                                    ${{ rtrim(rtrim(number_format($tier['monthly_cents'] / 100, 2), '0'), '.') }}/mo
                                    &middot;
                                    ${{ rtrim(rtrim(number_format($tier['yearly_cents'] / 100, 2), '0'), '.') }}/yr
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <fieldset>
                    <legend class="sr-only">Billing period</legend>
                    <div class="flex gap-4 text-sm">
                        @foreach (['monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
                            <label class="flex items-center gap-2">
                                <input type="radio" name="interval" value="{{ $value }}"
                                       class="accent-[var(--vg-edge)]"
                                       @checked(($subscription?->interval ?? 'monthly') === $value)>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                @unless ($subscription?->gateway_subscription_id)
                    {{--
                        The card is collected by the processor's own
                        hosted field, which replaces this input's value
                        with a one-use token before the form submits.
                        A card number never reaches this server, which is
                        the whole of this product's PCI position - so the field
                        is a hidden input and not a text box somebody
                        could type sixteen digits into.
                    --}}
                    <input type="hidden" name="payment_token" id="payment_token" value="{{ old('payment_token') }}">

                    <div id="card-field" class="rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-6 text-center text-xs text-[var(--vg-slate)]">
                        The card field is mounted here by {{ $gatewayName }}. Nothing is typed into {{ config('brand.name') }}.
                    </div>
                @endunless

                <button type="submit" class="rounded-lg bg-[var(--vg-edge)] px-4 py-2.5 font-bold text-[var(--vg-night)]">
                    {{ $subscription?->gateway_subscription_id ? 'Change plan' : 'Subscribe' }}
                </button>
            </form>

            @if ($portalAvailable)
                <form method="POST" action="{{ route('billing.portal') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="text-sm text-[var(--vg-blue)] underline">
                        Manage your card at {{ $gatewayName }}
                    </button>
                </form>
            @endif
        </div>
    @else
        {{--
            No processor. Said outright rather than shown as a checkout
            that fails: §5 builds for a change of processor, and the
            honest state before the first one is "not on sale".
        --}}
        <div class="panel mt-5 p-6">
            <h2 class="font-semibold">Subscribe</h2>
            <p class="mt-2 text-sm text-[var(--vg-slate)]">
                Paid plans are not on sale yet. No market has passed the gate, so there is
                nothing here worth charging for.
                <a class="text-[var(--vg-blue)] underline" href="{{ route('pricing') }}">See the plans</a>.
            </p>
        </div>
    @endif

    {{-- Billing history --}}
    <div class="panel mt-5 p-6">
        <h2 class="font-semibold">Billing history</h2>

        @if ($events->isEmpty())
            <p class="mt-2 text-sm text-[var(--vg-slate)]">Nothing has been charged to this account.</p>
        @else
            <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($events as $event)
                    <li class="flex items-baseline justify-between gap-4 py-2.5">
                        <span>
                            {{ $eventLabels[$event->type] ?? $event->type }}
                            <span class="block text-xs text-[var(--vg-slate)]">
                                {{ $event->occurred_at->toFormattedDateString() }}
                            </span>
                        </span>
                        <span class="shrink-0 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                            {{ $event->amount_cents === null ? '' : '$'.number_format($event->amount_cents / 100, 2) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="panel mt-5 p-6">
        <h2 class="font-semibold">Security</h2>
        <p class="mt-1 text-sm text-[var(--vg-slate)]">
            Two-factor authentication is
            <strong>{{ $user->hasTwoFactorEnabled() ? 'on' : 'off' }}</strong>.
        </p>
        <p class="mt-3">
            <a class="text-[var(--vg-blue)] underline" href="{{ route('two-factor.show') }}">
                Manage two-factor authentication
            </a>
        </p>
    </div>
</x-layouts.app>
