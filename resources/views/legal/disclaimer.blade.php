<x-legal-page title="Disclaimer">
    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">What this is</h2>

    <p>
        {{ config('brand.name') }} is an information and analytics product. It publishes
        probabilities, prices, measured edges and explanations of them. It is for information
        and entertainment.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">What this is not</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>
            <strong>Not a sportsbook.</strong> {{ config('brand.name') }} never takes a bet, never
            holds your money, and never pays out. There is no wallet, no deposit and no balance.
        </li>
        <li>
            <strong>Not a guarantee.</strong> Nothing here is a promise that a bet will win. A
            positive edge is a statement about long-run expectation measured against a market
            price, not about tonight.
        </li>
        <li>
            <strong>Not financial or legal advice.</strong> We do not know your circumstances and
            we are not advising you on them.
        </li>
        <li>
            <strong>Not a ruling on legality.</strong> Whether you may bet at all depends on where
            you are. That is yours to establish, not ours.
        </li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">About the numbers</h2>

    <p>
        A market is shown as <em>proven</em> only when a model has beaten the no-vig closing line
        on a holdout by more than chance explains, and is no worse calibrated than that line.
        Everything else is labelled <em>not yet proven</em> and produces analysis rather than
        picks. We would rather show you nothing than show you something we cannot stand behind.
    </p>

    <p>
        Past results do not predict future results. Any record shown on this site is published
        with its sample size, because a win rate without one is a number pretending to be
        evidence.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Odds data</h2>

    <p>
        Odds are shown for your use inside {{ config('brand.name') }} and are licensed on that
        basis. They may be out of date by the time you read them, they may differ from what your
        sportsbook is offering, and they may not be redistributed, resold or exported.
    </p>

    <p class="font-semibold">{{ config('vigil.responsible_gambling_line') }}</p>
</x-legal-page>
