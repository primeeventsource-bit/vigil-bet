<x-legal-page title="Subscription and cancellation policy">
    {{--
        The same rules as §4 of the terms, on a page of their own.

        Not a second, softer version - a customer who reads one and then
        the other must not find a difference. What changes is only that
        this is findable: the rules were correct and buried four
        headings into a document nobody opens until they are already
        unhappy.
    --}}
    <p>
        These are the billing terms from section 4 of the
        <a href="{{ route('legal.terms') }}" class="underline">terms of service</a>, on their own
        page so they can be read before you buy rather than after. They say the same thing; if
        anything here ever disagrees with the terms, the terms are what apply.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">What you are buying</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>
            A subscription to an analytics product. {{ config('brand.name') }} does not take bets,
            hold funds, or process a wager, and is not a sportsbook.
        </li>
        <li>Subscriptions renew until cancelled. The price is shown before you are charged.</li>
        <li>
            Plans are monthly or yearly. A yearly plan is charged once for the year and renews
            yearly.
        </li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Cancelling</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>
            Cancel from Account, in one click. There is no retention flow, no phone call and
            nobody to talk you out of it.
        </li>
        <li>
            Cancelling takes effect at the end of the period you have already paid for. You keep
            full access until that date.
        </li>
        <li>
            You can resume before that date and nothing changes. After it, the account moves to
            the free tier.
        </li>
        <li>
            <strong>Nothing is deleted when you cancel.</strong> Your bets, your record and your
            settings are all still there if you come back.
        </li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Refunds</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>
            <strong>No refunds on results.</strong> A losing week is not a fault in the product.
            This is stated plainly because the opposite promise is one the industry makes often
            and keeps rarely.
        </li>
        <li>
            If the product was broken or unavailable for a meaningful part of what you paid for,
            write to us and we will put it right.
        </li>
        <li>
            Where the law where you live gives you a right to cancel or a refund that is wider
            than this, that law applies and this policy does not reduce it.
        </li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Failed payments</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>
            If a payment fails we retry over several days and email you each time, saying which
            attempt it is and how long access continues.
        </li>
        <li>
            When the retries run out the account moves to the free tier. It is not closed and
            nothing is deleted.
        </li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Closing an account</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>
            To close an account entirely, write to
            {{ config('brand.company.support_email') }}.
        </li>
        <li>
            Self-exclusion is different and is not a cancellation. It is on the
            <a href="{{ route('safety') }}" class="underline">responsible gambling</a> page,
            it locks the account for a period you choose, and nobody here can shorten it &mdash;
            including whoever runs the product.
        </li>
    </ul>
</x-legal-page>
