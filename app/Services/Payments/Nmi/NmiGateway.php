<?php

declare(strict_types=1);

namespace App\Services\Payments\Nmi;

use App\Models\User;
use App\Services\Payments\GatewaySubscription;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PlanCatalogue;
use App\Services\Payments\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NMI, over the REST v5 API.
 *
 * The first driver, because NMI is what high-risk merchant accounts tend
 * to come with. Every wire detail NMI owns is in this one file; above it
 * the product deals in tiers, intervals and cents.
 *
 * Cards never reach these servers. The browser mints a token with NMI's
 * own hosted field and that token is all this driver ever sends, which is
 * the whole of Vigil's PCI position.
 *
 * Not yet exercised against a live merchant account - there is no account
 * to exercise it against. The shapes below come from NMI's published v5
 * reference and the tests fake the HTTP layer, so the first real
 * transaction is the first real proof. docs/STATUS.md says so too.
 */
class NmiGateway implements PaymentGateway
{
    private const DRIVER = 'nmi';

    private readonly PlanCatalogue $plans;

    public function __construct()
    {
        $this->plans = new PlanCatalogue(self::DRIVER);
    }

    public function name(): string
    {
        return self::DRIVER;
    }

    public function configured(): bool
    {
        return is_string($this->config('api_key')) && $this->config('api_key') !== '';
    }

    public function createCustomer(User $user, string $paymentToken): string
    {
        $response = $this->post('/v5/customers', [
            'payment_token' => $paymentToken,
            'email' => $user->email,
            'billing' => [
                'first_name' => str($user->name)->before(' ')->value(),
                'last_name' => str($user->name)->after(' ')->value() ?: '-',
                'email' => $user->email,
            ],
            // Our own id, so a record in NMI's portal can be traced back
            // to a customer here without a spreadsheet.
            'merchant_defined_field_1' => (string) $user->id,
        ]);

        $id = $response['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new PaymentException('NMI returned no customer vault id.');
        }

        return $id;
    }

    public function createSubscription(string $customerId, string $tier, string $interval): GatewaySubscription
    {
        $response = $this->post('/v5/subscriptions', [
            'plan_id' => $this->plans->gatewayPlanId($tier, $interval),
            'customer_vault' => ['id' => $customerId],
        ]);

        return $this->toSubscription($response, $tier, $interval);
    }

    public function changePlan(string $subscriptionId, string $tier, string $interval): GatewaySubscription
    {
        $response = $this->send('put', '/v5/subscriptions/'.rawurlencode($subscriptionId), [
            'plan_id' => $this->plans->gatewayPlanId($tier, $interval),
        ]);

        return $this->toSubscription($response, $tier, $interval, $subscriptionId);
    }

    /**
     * NMI has no "cancel at period end": a subscription is deleted, and
     * what the customer keeps until the period ends is kept by us, from
     * the period end already recorded in billing_events.
     *
     * That asymmetry is exactly why access is decided here and not by
     * asking the processor.
     */
    public function cancelAtPeriodEnd(string $subscriptionId): void
    {
        $this->send('delete', '/v5/subscriptions/'.rawurlencode($subscriptionId));
    }

    public function resume(string $subscriptionId): void
    {
        // Nothing to un-delete. BillingController starts a fresh
        // subscription against the stored card instead, which is why
        // this is not simply unsupported.
        throw new PaymentException(
            'NMI subscriptions cannot be resumed; start a new one against the stored card.',
            'Please start the plan again - your card is still on file.',
        );
    }

    public function refund(string $chargeId, int $amountCents): void
    {
        $this->post('/v5/refunds', [
            'payment_id' => $chargeId,
            // NMI takes major units as a decimal string; cents are the
            // only representation this product carries, so the
            // conversion happens here and nowhere else.
            'amount' => number_format($amountCents / 100, 2, '.', ''),
        ]);
    }

    public function handleWebhook(Request $request): ?WebhookEvent
    {
        $raw = $request->getContent();

        $this->verifySignature($request->header('Webhook-Signature'), $raw);

        $body = json_decode($raw, true);

        if (! is_array($body)) {
            throw PaymentException::badSignature('body was not JSON');
        }

        $id = $body['event_id'] ?? null;
        $type = $body['event_type'] ?? null;

        if (! is_string($id) || ! is_string($type)) {
            throw new PaymentException('NMI webhook had no event id or type.');
        }

        return (new NmiEventTranslator)->translate($id, $type, (array) ($body['event_body'] ?? []), $body);
    }

    public function hostsBillingPortal(): bool
    {
        return false;
    }

    public function customerPortalUrl(string $customerId): ?string
    {
        // NMI hosts nothing a customer should be sent to. Account has to
        // cope with that rather than linking somewhere that is not there.
        return null;
    }

    /**
     * Verify the signature, in constant time, over the untouched bytes.
     *
     * The header is `t=<nonce>,s=<hex>` and the signed string is the
     * nonce, a dot, then the raw body. Parsing and re-encoding the JSON
     * first would break it, which is why this takes the raw content.
     *
     * The nonce is a nonce and not a timestamp, so there is no replay
     * window to enforce here: the defence against replay is that an
     * event id can only ever be written once, which the unique column on
     * billing_events guarantees.
     *
     * @throws PaymentException
     */
    private function verifySignature(?string $header, string $raw): void
    {
        $key = $this->config('webhook_key');

        if (! is_string($key) || $key === '') {
            // Refusing is the only safe answer. An unverified webhook is
            // an anonymous stranger asking for a free subscription.
            throw PaymentException::badSignature('no signing key is configured');
        }

        if (! is_string($header) || $header === '') {
            throw PaymentException::badSignature('header missing');
        }

        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[(string) $k] = (string) $v;
        }

        $nonce = $parts['t'] ?? '';
        $signature = $parts['s'] ?? '';

        if ($nonce === '' || $signature === '') {
            throw PaymentException::badSignature('header was not t=,s=');
        }

        $expected = hash_hmac('sha256', $nonce.'.'.$raw, $key);

        if (! hash_equals($expected, strtolower($signature))) {
            throw PaymentException::badSignature('did not match');
        }
    }

    /** @param  array<string, mixed>  $response */
    private function toSubscription(array $response, string $tier, string $interval, ?string $fallbackId = null): GatewaySubscription
    {
        $id = $response['id'] ?? $fallbackId;

        if (! is_string($id) || $id === '') {
            throw new PaymentException('NMI returned no subscription id.');
        }

        return new GatewaySubscription(
            id: $id,
            tier: $tier,
            interval: $interval,
            periodEnd: $this->date($response['next_charge_date'] ?? $response['current_period_end'] ?? null),
            amountCents: PlanCatalogue::amountCents($tier, $interval),
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            // A date we cannot read becomes no date, which makes access
            // fall back to Free rather than to "paid until some time we
            // guessed at".
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->send('post', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $body = []): array
    {
        if (! $this->configured()) {
            throw PaymentException::notConfigured(self::DRIVER);
        }

        try {
            $response = Http::withHeaders([
                // v5 takes the key raw, not as a Bearer token.
                'Authorization' => (string) $this->config('api_key'),
                'Accept' => 'application/json',
            ])
                ->asJson()
                ->timeout(20)
                ->retry(2, 250, throw: false)
                ->send(strtoupper($method), $this->config('base_url').$path, ['json' => $body]);
        } catch (ConnectionException $e) {
            throw PaymentException::unreachable($e->getMessage());
        }

        $decoded = $response->json();
        $decoded = is_array($decoded) ? $decoded : [];

        if ($response->failed()) {
            /*
             * Logged without the request body. The body carries a
             * payment token, and a token in a log file is a token in
             * every backup of that log file.
             */
            Log::warning('NMI call failed', [
                'path' => $path,
                'status' => $response->status(),
                'message' => $decoded['message'] ?? $decoded['error'] ?? null,
            ]);

            $detail = (string) ($decoded['message'] ?? $decoded['error'] ?? 'HTTP '.$response->status());

            throw in_array($response->status(), [400, 402], true)
                ? PaymentException::declined($detail)
                : PaymentException::unreachable($detail);
        }

        return $decoded;
    }

    private function config(string $key): mixed
    {
        // Read lazily rather than captured in the constructor, because
        // this is a singleton and a test that sets a key afterwards
        // would otherwise be talking to a driver that never sees it.
        return config('payments.drivers.'.self::DRIVER.'.'.$key);
    }
}
