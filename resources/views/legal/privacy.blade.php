<x-legal-page title="Privacy">
    <p>
        The short version: we collect what the product needs to work, we do not sell it, and we
        deliberately do not hold some things we could.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">What we hold</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>Your name and email address.</li>
        <li>A hash of your password. We never hold the password itself.</li>
        <li>
            <strong>Your birth year, and whether the age check passed</strong> — not your date of
            birth. The form asks for the full date because that is the only honest way to ask the
            question, and the month and day are discarded in the same request. The year is enough
            to show we checked.
        </li>
        <li>Bets you log, and your bankroll settings, if you use those features.</li>
        <li>Billing records: what you were charged and when. See the next section about cards.</li>
        <li>Basic request data — IP address and browser — for security and rate limiting.</li>
        <li>
            <strong>If you start a free trial:</strong> when it started and when it ends, and an
            approximate location — country and region, worked out from your IP address using a
            local MaxMind database. Your IP address is not sent anywhere to do this.
        </li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">The free trial record</h2>

    <p>
        One trial is available per person, so we have to keep a record that outlasts any single
        account. That record holds <strong>a one-way hash of your email address, not the address
        itself</strong> — enough to recognise that a trial has been used, and not enough to read
        back as a list of who signed up. The same is true of the IP address: we store a hash, so
        we can see that several trials came from one network without keeping another copy of
        where you were.
    </p>

    <p>
        The approximate location is kept for one purpose, and it is worth being plain about what
        that purpose is not. It does not decide whether you get a trial — nobody is refused
        because of where they are, and a shared office or a university network is not treated as
        one person. It is there so we can see unusual patterns and so we know which country an
        account is in, which matters because betting law is not the same everywhere.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Card details</h2>

    <p>
        <strong>We never see them.</strong> Card fields are hosted by our payment processor and
        the card number never touches our servers or our logs. We hold a token that lets us bill
        you and nothing that could be used to charge a card anywhere else.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">What we do not do</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li>We do not sell your data. Not to anybody, not in aggregate, not ever.</li>
        <li>We do not share your betting activity with sportsbooks.</li>
        <li>We do not run advertising trackers across the site.</li>
        <li>Analytics are privacy-friendly and cookieless: counts of pages, not profiles of people.</li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Email</h2>

    <p>
        Account email — receipts, password resets, security notices — is part of the service and
        cannot be switched off while you have an account. Marketing email is separate, requires
        you to confirm the address first, and every message carries an unsubscribe link and our
        postal address.
    </p>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Your data, on request</h2>

    <ul class="list-disc space-y-2 pl-5">
        <li><strong>Export.</strong> Download your account data and your own bet history from Account.</li>
        <li>
            <strong>Deletion.</strong> Delete your account from Account. We remove your personal
            data and keep only what the law requires us to keep, such as billing records.
        </li>
        <li>
            One thing deletion does not undo: an active self-exclusion. Closing an account cannot
            be used as a way around a lock you set on yourself.
        </li>
    </ul>

    <h2 class="font-[family-name:var(--font-display)] text-xl font-semibold">Who else sees it</h2>

    <p>
        Only the services that make the product run — hosting, email delivery, payment
        processing — and only the part each one needs. We do not pass data to anybody else
        unless the law compels us to.
    </p>

    <p>
        Questions, or a request about your data: {{ config('brand.company.support_email') }}.
    </p>
</x-legal-page>
