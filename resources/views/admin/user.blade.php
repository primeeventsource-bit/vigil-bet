<x-layouts.admin :title="$customer->name">
    <p class="mt-3 font-[family-name:var(--font-mono)] text-sm text-[var(--vg-slate)]">
        {{ $customer->email }}
        @unless ($customer->email_verified_at)
            <span class="badge badge-pass ml-2">unconfirmed</span>
        @endunless
        @foreach ($customer->roles as $role)
            <span class="badge badge-edge ml-2">{{ $role->name }}</span>
        @endforeach
        @if ($customer->isSuspended())
            <span class="badge badge-loss ml-2">suspended</span>
        @endif
    </p>

    @if ($customer->isSuspended())
        {{--
            Said at the top, in the words the person themselves sees on
            the sign-in page. A suspension buried below four panels is a
            suspension somebody re-applies by accident.
        --}}
        <div class="panel mt-4 border-l-2 border-[var(--vg-loss)] p-4">
            <p class="text-sm font-semibold text-[var(--vg-paper)]">
                Suspended {{ $customer->suspended_at?->diffForHumans() }}
                @if ($customer->suspendedBy)
                    by {{ $customer->suspendedBy->email }}
                @endif
            </p>
            <p class="mt-1 text-xs text-[var(--vg-slate)]">{{ $customer->suspended_reason }}</p>
        </div>
    @endif

    <section class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-tile label="Effective plan"
                     :value="$customer->subscription?->effectiveTier() ?? 'free'"
                     :sample="$customer->subscription?->describe() ?? 'no subscription row'" />

        <x-stat-tile label="Tracked bets" :value="$bets->count() ?: null"
                     sample="most recent twenty shown" />

        <x-stat-tile label="AI spend"
                     :value="'$'.number_format($aiSpend / 100000, 2)"
                     sample="reads generated for this customer" />

        <x-stat-tile label="Overrides" :value="$customer->entitlementOverrides->count() ?: null"
                     :sample="$customer->entitlementOverrides->isEmpty() ? 'plan decides' : 'beats the plan'" />
    </section>

    {{-- Plan --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Plan</h2>
        <p class="mt-1 text-xs text-[var(--vg-slate)]">
            Recorded as a billing event rather than written onto the subscription, so the history
            stays complete and the projection is still derived from the log.
        </p>

        <x-admin.reasoned-form :action="route('admin.tier', $customer)" label="Change tier">
            <label class="block text-xs">
                <span class="text-[var(--vg-slate)]">Tier</span>
                <select name="tier" required
                        class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    @foreach ($tiers as $tier)
                        <option value="{{ $tier }}" @selected($customer->subscription?->tier === $tier)>
                            {{ $tier }}
                        </option>
                    @endforeach
                </select>
            </label>
        </x-admin.reasoned-form>
    </section>

    {{-- Overrides --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Entitlement overrides</h2>
        <p class="mt-1 text-xs text-[var(--vg-slate)]">
            Beats the plan in both directions. Expiry is checked on read, so a comp cannot outlive
            its date because a scheduler was down.
        </p>

        @if ($customer->entitlementOverrides->isNotEmpty())
            <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($customer->entitlementOverrides as $override)
                    <li class="py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <p class="font-[family-name:var(--font-mono)] text-xs">
                                {{ $override->feature }}
                                <span @class([
                                    'badge ml-1',
                                    'badge-edge' => $override->granted,
                                    'badge-pass' => ! $override->granted,
                                ])>{{ $override->granted ? 'granted' : 'withdrawn' }}</span>
                                @if ($override->hasExpired())
                                    <span class="badge badge-pass ml-1">expired</span>
                                @endif
                            </p>
                            <p class="text-xs text-[var(--vg-slate)]">
                                {{ $override->expires_at
                                    ? 'until '.$override->expires_at->toFormattedDateString()
                                    : 'no expiry' }}
                            </p>
                        </div>
                        <p class="mt-1 text-xs text-[var(--vg-slate)]">{{ $override->reason }}</p>

                        <x-admin.reasoned-form :action="route('admin.override.remove', $customer)"
                                               method="DELETE" label="Remove">
                            <input type="hidden" name="feature" value="{{ $override->feature }}">
                        </x-admin.reasoned-form>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-admin.reasoned-form :action="route('admin.override', $customer)" label="Save override">
            <label class="block text-xs">
                <span class="text-[var(--vg-slate)]">Feature</span>
                <select name="feature" required
                        class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    @foreach ($features as $feature)
                        <option value="{{ $feature }}">{{ $feature }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block text-xs">
                <span class="text-[var(--vg-slate)]">Direction</span>
                <select name="granted" required
                        class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    <option value="1">Grant</option>
                    <option value="0">Withdraw</option>
                </select>
            </label>

            <label class="block text-xs">
                <span class="text-[var(--vg-slate)]">Expires (optional)</span>
                <input name="expires_at" type="date"
                       class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>
        </x-admin.reasoned-form>
    </section>

    {{-- Billing --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Billing history</h2>

        @if ($billing->isEmpty())
            <p class="mt-2 text-sm text-[var(--vg-slate)]">Nothing has been charged to this account.</p>
        @else
            <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($billing as $event)
                    <li class="py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <p>
                                <span class="font-[family-name:var(--font-mono)] text-xs">{{ $event->type }}</span>
                                <span class="block text-xs text-[var(--vg-slate)]">
                                    {{ $event->occurred_at->toDayDateTimeString() }}
                                    @if ($event->reason) &middot; {{ $event->reason }} @endif
                                </span>
                            </p>
                            <p class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                {{ $event->amount_cents === null
                                    ? ''
                                    : '$'.number_format($event->amount_cents / 100, 2) }}
                            </p>
                        </div>

                        @if (in_array($event->type, ['subscription.started', 'subscription.renewed'], true)
                            && $event->amount_cents > 0)
                            {{--
                                A refund, with the amount and a reason.
                                Recorded only if the processor accepts
                                it - recording first would show a
                                customer money back that nobody sent.
                            --}}
                            <x-admin.reasoned-form :action="route('admin.refund')" label="Refund"
                                                   confirm="Refund this charge? This moves real money.">
                                <input type="hidden" name="billing_event_id" value="{{ $event->id }}">
                                <label class="block text-xs">
                                    <span class="text-[var(--vg-slate)]">Amount (blank = all)</span>
                                    <input name="amount" type="number" step="0.01" min="0.01"
                                           max="{{ $event->amount_cents / 100 }}"
                                           class="mt-1 w-28 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                                </label>
                            </x-admin.reasoned-form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        {{-- Bets --}}
        <section class="panel p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Recent bets</h2>

            @if ($bets->isEmpty())
                <p class="mt-2 text-sm text-[var(--vg-slate)]">Nothing logged.</p>
            @else
                {{--
                    Read-only. There is no way here to edit a bet: the
                    event log behind it is append-only in the database
                    and this page could not write it if it tried.
                --}}
                <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                    @foreach ($bets as $bet)
                        <li class="flex items-baseline justify-between gap-3 py-2.5">
                            <span>
                                {{ $bet->selection }}
                                <span class="block text-xs text-[var(--vg-slate)]">
                                    {{ $bet->sport }} &middot; ${{ number_format($bet->stake_cents / 100, 2) }}
                                    &middot; {{ $bet->placed_at?->toFormattedDateString() }}
                                </span>
                            </span>
                            <x-app.bet-status :bet="$bet" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Alerts --}}
        <section class="panel p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Recent alerts</h2>

            @if ($alerts->isEmpty())
                <p class="mt-2 text-sm text-[var(--vg-slate)]">None sent or skipped.</p>
            @else
                <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                    @foreach ($alerts as $delivery)
                        <li class="flex items-baseline justify-between gap-3 py-2.5">
                            <span class="min-w-0">
                                {{ $delivery->alert?->type ?? 'alert' }}
                                <span class="block text-xs text-[var(--vg-slate)]">
                                    {{ $delivery->channel }}
                                    &middot; {{ $delivery->delivered_at->diffForHumans() }}
                                    @if ($delivery->reason)
                                        &middot; {{ str_replace('_', ' ', $delivery->reason) }}
                                    @endif
                                </span>
                            </span>
                            <span @class([
                                'badge shrink-0',
                                'badge-edge' => $delivery->status === 'sent',
                                'badge-pass' => $delivery->status === 'skipped',
                            ])>{{ ucfirst($delivery->status) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- What has been done to this account --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Changes to this account</h2>

        @if ($audit->isEmpty())
            <p class="mt-2 text-sm text-[var(--vg-slate)]">Nothing has been changed by hand.</p>
        @else
            <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($audit as $event)
                    <li class="py-2.5">
                        <p>
                            <span class="font-[family-name:var(--font-mono)] text-xs">{{ $event->action }}</span>
                            <span class="text-[var(--vg-slate)]">
                                by {{ $event->actor?->name ?? 'the system' }}
                                &middot; {{ $event->created_at->diffForHumans() }}
                            </span>
                        </p>
                        @if ($event->reason)
                            <p class="mt-0.5 text-xs text-[var(--vg-slate)]">{{ $event->reason }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{--
        Account administration, last on the page on purpose. Everything
        above answers "what is going on with this person"; this is the
        part that changes it, and it should take a deliberate scroll.

        Every control is wrapped in its own @can. A policy refusal and a
        hidden button are not the same protection - the policy is the
        one that holds - but showing an operator a button that will
        refuse them is its own small lie.
    --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Account</h2>

        @if ($customer->isSuperAdmin())
            <p class="mt-2 text-xs text-[var(--vg-muted)]">
                This account holds {{ config('roles.super_admin') }}. It cannot be edited, suspended,
                demoted or deleted from here by anybody, including another {{ config('roles.super_admin') }}.
                A reset link is the one thing that can still be sent, so a locked-out holder has a way back.
            </p>
        @endif

        @can('update', $customer)
            <div class="mt-4 border-t border-[var(--vg-line)] pt-4">
                <h3 class="text-xs font-bold uppercase tracking-wide text-[var(--vg-slate)]">Details</h3>
                <p class="mt-1 text-xs text-[var(--vg-muted)]">
                    Changing the address clears its confirmation. The old one proved the old mailbox.
                </p>

                <x-admin.reasoned-form :action="route('admin.users.update', $customer)" label="Save">
                    <label class="block text-xs">
                        <span class="text-[var(--vg-slate)]">First name</span>
                        <input name="first_name" required maxlength="60"
                               value="{{ Str::before($customer->name, ' ') }}"
                               class="mt-1 w-32 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    </label>
                    <label class="block text-xs">
                        <span class="text-[var(--vg-slate)]">Last name</span>
                        <input name="last_name" required maxlength="60"
                               value="{{ Str::after($customer->name, ' ') }}"
                               class="mt-1 w-32 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    </label>
                    <label class="block text-xs">
                        <span class="text-[var(--vg-slate)]">Email</span>
                        <input name="email" type="email" required maxlength="255" value="{{ $customer->email }}"
                               class="mt-1 w-56 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    </label>
                </x-admin.reasoned-form>
            </div>
        @endcan

        @can('assignRole', $customer)
            <div class="mt-4 border-t border-[var(--vg-line)] pt-4">
                <h3 class="text-xs font-bold uppercase tracking-wide text-[var(--vg-slate)]">Role</h3>
                <p class="mt-1 text-xs text-[var(--vg-muted)]">
                    Removing {{ config('roles.admin') }} ends every session this account has open.
                </p>

                <x-admin.reasoned-form :action="route('admin.users.role', $customer)" label="Set role">
                    <label class="block text-xs">
                        <span class="text-[var(--vg-slate)]">Role</span>
                        <select name="role" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                            <option value="{{ config('roles.user') }}"
                                @selected(! $customer->hasRole(config('roles.admin')))>{{ config('roles.user') }}</option>
                            <option value="{{ config('roles.admin') }}"
                                @selected($customer->hasRole(config('roles.admin')))>{{ config('roles.admin') }}</option>
                        </select>
                    </label>
                </x-admin.reasoned-form>
            </div>
        @endcan

        @can('suspend', $customer)
            <div class="mt-4 border-t border-[var(--vg-line)] pt-4">
                <h3 class="text-xs font-bold uppercase tracking-wide text-[var(--vg-slate)]">Access</h3>

                @if ($customer->isSuspended())
                    <p class="mt-1 text-xs text-[var(--vg-muted)]">
                        Reinstating lets this account sign in again. It does not lift a self-exclusion,
                        which is the customer's own and cannot be shortened from here.
                    </p>
                    <x-admin.reasoned-form :action="route('admin.users.reinstate', $customer)"
                                           label="Reinstate" />
                @else
                    <p class="mt-1 text-xs text-[var(--vg-muted)]">
                        The reason is shown to this person on the sign-in page, so write it as a sentence.
                        Suspending ends every open session immediately.
                    </p>
                    <x-admin.reasoned-form :action="route('admin.users.suspend', $customer)"
                                           label="Suspend"
                                           confirm="Suspend this account and end its sessions?" />
                @endif
            </div>
        @endcan

        <div class="mt-4 grid gap-4 border-t border-[var(--vg-line)] pt-4 sm:grid-cols-2">
            @can('revokeSessions', $customer)
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wide text-[var(--vg-slate)]">Sessions</h3>
                    <p class="mt-1 text-xs text-[var(--vg-muted)]">
                        Signs this account out of every device without suspending it.
                    </p>
                    <x-admin.reasoned-form :action="route('admin.users.revoke', $customer)"
                                           label="Sign out everywhere" />
                </div>
            @endcan

            @can('resetPassword', $customer)
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wide text-[var(--vg-slate)]">Password</h3>
                    <p class="mt-1 text-xs text-[var(--vg-muted)]">
                        Sends a reset link. No operator ever sets a password on somebody's behalf.
                    </p>
                    <x-admin.reasoned-form :action="route('admin.users.reset', $customer)"
                                           label="Send reset link" />
                </div>
            @endcan
        </div>
    </section>
</x-layouts.admin>
