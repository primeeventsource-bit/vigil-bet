<x-legal-page title="Terms of service">
    <p>
        These terms cover your use of {{ config('brand.name') }}, operated by
        {{ config('brand.company.legal_name') }}. Using the site means you accept them.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">1. What you are buying</h2>

    <p>
        A subscription to information and tools: odds, model probabilities, measured edges,
        explanations, and a record of what was published and when. You are not buying bets,
        outcomes, or a share of anything.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">2. No guarantees</h2>

    <p>
        We make no promise about results. We do not guarantee that any pick wins, that any
        edge is realised, or that a model that has been proven stays proven. Betting loses money
        for most people who do it, and nothing here changes that.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">3. Your responsibility</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>You must be at least {{ config('vigil.minimum_age') }} years old.</li>
        <li>
            You are responsible for knowing whether sports betting is lawful where you are, and
            for obeying that law. We do not check it for you and we are not able to.
        </li>
        <li>Every bet you place is your decision, placed with your own sportsbook.</li>
        <li>Your account is yours alone. Do not share it.</li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">4. Billing, cancelling, refunds</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>Subscriptions renew until cancelled. Prices are shown before you are charged.</li>
        <li>
            Cancel any time from Account. It takes one click, it takes effect at the end of the
            period you have paid for, and you keep access until then.
        </li>
        <li>
            <strong>No refunds on results.</strong> A losing week is not a fault in the product
            and is not grounds for a refund. If the product was broken or unavailable, write to
            us and we will put it right.
        </li>
        <li>
            If payment fails we retry over several days and tell you each time, then move the
            account to Free. Your data stays.
        </li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">5. Odds data</h2>

    <p>
        Odds shown here are licensed for display inside {{ config('brand.name') }}. You may not
        redistribute, resell, republish, scrape or export them, or use them to build a competing
        feed. You may export your own bet history at any time — that is yours.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">6. Acceptable use</h2>

    <p>
        No scraping, no automated bulk collection, no sharing credentials, no reselling access,
        and nothing that interferes with the service for anybody else.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">7. Ending an account</h2>

    <p>
        You can close your account whenever you like. We may suspend or close an account that
        breaks these terms, is used fraudulently, or is used by somebody under
        {{ config('vigil.minimum_age') }}.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">8. Liability</h2>

    <p>
        To the extent the law allows, our liability is limited to what you paid us in the
        twelve months before the claim. We are not liable for betting losses. That is the whole
        point of sections 2 and 3.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">9. Changes</h2>

    <p>
        If these terms change in a way that matters, we will tell you before it takes effect.
    </p>

    <p class="font-semibold">{{ config('vigil.responsible_gambling_line') }}</p>
</x-legal-page>
