<x-legal-page title="Responsible gambling">
    <p class="rounded-md border border-[var(--vg-warning)] bg-[color-mix(in_srgb,var(--vg-warning)_10%,transparent)] px-4 py-3 font-semibold">
        {{ config('vigil.responsible_gambling_line') }}
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Where to get help</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>
            <strong>1-800-GAMBLER</strong> — free, confidential, 24 hours, every day.
            <a class="text-[var(--vg-link)] underline" href="{{ config('vigil.helpline.url') }}">
                {{ config('vigil.helpline.url') }}
            </a>
        </li>
        <li>Most US states run their own helpline as well; your state's is usually quicker.</li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">What this product deliberately does not do</h2>

    <p>
        A lot of what makes gambling products harmful is design, not content. These are the
        things {{ config('brand.name') }} will not build, and they are not up for revisiting
        later:
    </p>

    <ul class="list-disc space-y-2 pl-5">
        <li>It never takes a bet, holds your money, or pays anything out.</li>
        <li>There is no leaderboard, no streak, no prize and no contest.</li>
        <li>There is nothing to chase: no bonus that expires, no reward for betting more.</li>
        <li>It says <em>pass</em> as readily as <em>pick</em>, and it says it with the same confidence.</li>
        <li>Cancelling takes one click and we will not put anything in the way of it.</li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Tools you can turn on</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>
            <strong>Loss limits.</strong> Daily and weekly caps. When a limit is reached the
            product stops suggesting anything at all until the window resets. There is no
            override, because a limit you can override on the night is not a limit.
        </li>
        <li>
            <strong>Self-exclusion.</strong> Lock yourself out for
            {{ collect(config('vigil.self_exclusion_days'))->join(', ', ' or ') }} days.
            <strong>This cannot be undone early</strong>, by you or by us. Asking us nicely will
            not work, and that is the feature.
        </li>
        <li>
            <strong>Hide the app.</strong> Mute alerts and empty the dashboard for a period you
            choose, without closing the account.
        </li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Signs worth taking seriously</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>Betting more than you planned, or more than you can afford to lose.</li>
        <li>Betting to win back what you lost.</li>
        <li>Hiding how much you bet from people close to you.</li>
        <li>Borrowing to bet.</li>
        <li>Feeling you cannot stop.</li>
    </ul>

    <p>
        If any of those are familiar, the helpline above is a better use of the next ten minutes
        than this website is.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">{{ config('vigil.minimum_age') }}+ only</h2>

    <p>
        You must be at least {{ config('vigil.minimum_age') }} to hold an account. We check at
        signup and we keep a record that we checked.
    </p>
</x-legal-page>
