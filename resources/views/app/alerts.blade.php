<x-layouts.app title="Alerts">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Alerts</h1>
    <p class="mt-1 max-w-2xl text-sm text-[var(--vg-slate)]">
        Both channels start off, and nothing is sent until you turn one on. There is a daily
        ceiling on each — a product that tells you about every price wobble is a product you
        turn off.
    </p>

    @if (session('status'))
        <p class="mt-4 rounded-lg border border-[color-mix(in_srgb,var(--vg-edge)_40%,transparent)] bg-[color-mix(in_srgb,var(--vg-edge)_10%,transparent)] px-4 py-3 text-sm">
            {{ session('status') }}
        </p>
    @endif

    <form method="POST" action="{{ route('alerts.update') }}" class="mt-6 space-y-5">
        @csrf

        {{-- Channels --}}
        <section class="panel p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">How to tell you</h2>

            <label class="mt-4 flex items-start gap-3 text-sm">
                <input type="checkbox" name="email_enabled" value="1" class="mt-0.5 accent-[var(--vg-edge)]"
                       @checked($preference->email_enabled)>
                <span>
                    <span class="font-medium">Email</span>
                    <span class="block text-xs text-[var(--vg-slate)]">
                        Up to {{ $caps['email'] }} a day. Only to a confirmed address.
                    </span>
                </span>
            </label>

            <label class="mt-4 flex items-start gap-3 text-sm">
                <input type="checkbox" name="push_enabled" value="1" class="mt-0.5 accent-[var(--vg-edge)]"
                       @checked($preference->push_enabled) @disabled(! $pushConfigured)>
                <span>
                    <span class="font-medium">Web push</span>
                    <span class="block text-xs text-[var(--vg-slate)]">
                        @if ($pushConfigured)
                            Up to {{ $caps['push'] }} a day, to this browser.
                            <span id="push-state" class="block"></span>
                        @else
                            {{--
                                Said outright. A switch that did nothing
                                would be worse than one that is off and
                                explains itself.
                            --}}
                            Not available yet — no push keys are configured on this deployment,
                            so nothing could be sent.
                        @endif
                    </span>
                </span>
            </label>

            @if ($pushConfigured && $subscriptions->isNotEmpty())
                <p class="mt-3 text-xs text-[var(--vg-slate)]">
                    {{ $subscriptions->count() }}
                    {{ Str::plural('browser', $subscriptions->count()) }} registered.
                </p>
            @endif
        </section>

        {{-- Triggers --}}
        <section class="panel p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">What to tell you about</h2>

            <label class="mt-4 flex items-start gap-3 text-sm">
                <input type="checkbox" name="on_new_pick" value="1" class="mt-0.5 accent-[var(--vg-edge)]"
                       @checked($preference->on_new_pick)>
                <span>
                    <span class="font-medium">A pick is published</span>
                    <span class="block text-xs text-[var(--vg-slate)]">
                        Only picks that go on the public record, before kickoff.
                    </span>
                </span>
            </label>

            <label class="mt-4 flex items-start gap-3 text-sm">
                <input type="checkbox" name="on_line_move" value="1" class="mt-0.5 accent-[var(--vg-edge)]"
                       @checked($preference->on_line_move)>
                <span>
                    <span class="font-medium">A line moves on a game you watch</span>
                    <span class="block text-xs text-[var(--vg-slate)]">
                        Watched games only. Everything else is noise.
                    </span>
                </span>
            </label>

            <label class="mt-5 block max-w-xs text-sm">
                <span class="text-[var(--vg-slate)]">Tell me when a price moves by at least</span>
                <input type="number" name="line_move_threshold" min="5" max="200"
                       value="{{ old('line_move_threshold', $preference->line_move_threshold) }}"
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <span class="mt-1 block text-[11px] text-[var(--vg-slate)]">
                    In American odds points. 15 is a real move; 5 is usually noise.
                </span>
            </label>
        </section>

        {{-- Quiet hours --}}
        <section class="panel p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Quiet hours</h2>
            <p class="mt-1 text-xs text-[var(--vg-slate)]">
                Nothing is sent during these hours, in your own timezone. A three in the morning
                notification about a line move is the opposite of helping.
            </p>

            <div class="mt-4 flex flex-wrap items-end gap-3">
                <label class="block text-sm">
                    <span class="text-[var(--vg-slate)]">From</span>
                    <select name="quiet_from_hour"
                            class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                        <option value="">&mdash;</option>
                        @for ($h = 0; $h < 24; $h++)
                            <option value="{{ $h }}" @selected((int) $preference->quiet_from_hour === $h && $preference->quiet_from_hour !== null)>
                                {{ sprintf('%02d:00', $h) }}
                            </option>
                        @endfor
                    </select>
                </label>

                <label class="block text-sm">
                    <span class="text-[var(--vg-slate)]">To</span>
                    <select name="quiet_to_hour"
                            class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                        <option value="">&mdash;</option>
                        @for ($h = 0; $h < 24; $h++)
                            <option value="{{ $h }}" @selected((int) $preference->quiet_to_hour === $h && $preference->quiet_to_hour !== null)>
                                {{ sprintf('%02d:00', $h) }}
                            </option>
                        @endfor
                    </select>
                </label>

                <label class="block text-sm">
                    <span class="text-[var(--vg-slate)]">Timezone</span>
                    <select name="timezone"
                            class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                        @foreach (DateTimeZone::listIdentifiers() as $zone)
                            <option value="{{ $zone }}" @selected($preference->timezone === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </section>

        <button type="submit" class="rounded-lg bg-[var(--vg-edge)] px-4 py-2.5 font-bold text-[var(--vg-night)]">
            Save
        </button>
    </form>

    {{-- Watched games --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Games you watch</h2>

        @if ($watches->isEmpty())
            <p class="mt-2 text-sm text-[var(--vg-slate)]">
                None. Line-move alerts only cover games on this list.
            </p>
        @else
            <ul class="mt-3 divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($watches as $watch)
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <span>
                            {{ $watch['matchup'] }}
                            @if ($watch['starts_at'])
                                <span class="block text-xs text-[var(--vg-slate)]">
                                    {{ $watch['starts_at']->toDayDateTimeString() }}
                                </span>
                            @endif
                        </span>
                        <form method="POST" action="{{ route('alerts.unwatch') }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="edge_lab_event_id" value="{{ $watch['edge_lab_event_id'] }}">
                            <button type="submit" class="text-xs text-[var(--vg-slate)] underline">Stop watching</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($watchable->isEmpty())
            <p class="mt-4 text-xs text-[var(--vg-slate)]">
                No upcoming games are stored yet. Games appear here once the schedule has synced.
            </p>
        @else
            <form method="POST" action="{{ route('alerts.watch') }}" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <label class="block text-sm">
                    <span class="text-[var(--vg-slate)]">Add a game</span>
                    <select name="edge_lab_event_id" required
                            class="mt-1 max-w-xs rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                        @foreach ($watchable as $event)
                            <option value="{{ $event->edge_lab_id }}">
                                {{ $event->matchup() }} — {{ $event->starts_at?->format('j M H:i') }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="rounded-lg border border-[var(--vg-line)] px-4 py-2.5 text-sm font-semibold">
                    Watch
                </button>
            </form>
        @endif
    </section>

    {{-- The log, skips included --}}
    <section class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-[var(--vg-line)] px-5 py-3.5 font-[family-name:var(--font-display)] font-semibold">
            What we have sent you
        </h2>

        @if ($deliveries->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-[var(--vg-slate)]">
                Nothing yet.
            </p>
        @else
            {{--
                Skips are in this list on purpose. "Why did I not get
                that one" is a question a switchboard cannot answer and a
                log can.
            --}}
            <ul class="divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($deliveries as $delivery)
                    <li class="flex items-start justify-between gap-4 px-5 py-3">
                        <span class="min-w-0">
                            <span class="font-medium">
                                {{ $delivery->alert?->payload['selection']
                                    ?? $delivery->alert?->payload['matchup']
                                    ?? 'Alert' }}
                            </span>
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

    @if ($pushConfigured)
        {{--
            Push registration. Plain JavaScript rather than a framework:
            this is twenty lines of browser API, and a dependency here
            would be a dependency on the one page that has to work when
            everything else is switched off.
        --}}
        <script>
            (() => {
                const key = @json($vapidPublicKey);
                const state = document.getElementById('push-state');
                const toggle = document.querySelector('input[name="push_enabled"]');

                if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                    if (state) state.textContent = 'This browser does not support web push.';
                    if (toggle) toggle.disabled = true;
                    return;
                }

                const bytes = (base64) => {
                    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4))
                        .replace(/-/g, '+').replace(/_/g, '/');
                    const raw = atob(padded);
                    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
                };

                const register = async () => {
                    const permission = await Notification.requestPermission();

                    if (permission !== 'granted') {
                        // Said rather than silently left off: a customer
                        // who denied the prompt should know that is why.
                        if (state) state.textContent = 'This browser has blocked notifications.';
                        if (toggle) toggle.checked = false;
                        return;
                    }

                    const registration = await navigator.serviceWorker.register('/sw.js');
                    const subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: bytes(key),
                    });

                    await fetch(@json(route('alerts.subscribe')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(subscription.toJSON()),
                    });

                    if (state) state.textContent = 'This browser is registered.';
                };

                if (toggle) {
                    toggle.addEventListener('change', () => {
                        if (toggle.checked) {
                            register().catch(() => {
                                if (state) state.textContent = 'Could not register this browser.';
                            });
                        }
                    });
                }

                navigator.serviceWorker.getRegistration('/sw.js').then((existing) => {
                    if (existing && state) state.textContent = 'This browser is registered.';
                });
            })();
        </script>
    @endif
</x-layouts.app>
