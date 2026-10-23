<?php

declare(strict_types=1);

namespace App\Services\Payments\Stripe;

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

/**
 * Stripe, behind a flag.
 *
 * §5 asks for this as the second driver, and §13 asks that switching
 * between the two takes no code changes. It is flagged off rather than
 * merely unconfigured because Stripe may not accept this business at
 * all: their prohibited-business list names sports forecasting with a
 * monetary or material prize, and Vigil sells forecasts. The driver
 * exists so that a change of processor is an afternoon rather than a
 * rewrite, not because it is expected to be the one in use.
 *
 * Cards never reach these servers: the browser mints a PaymentMethod
 * with Stripe's own hosted fields and that id is all this driver sends.
 */
class StripeGateway implements PaymentGateway
{
    private const DRIVER = 'stripe';

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
        return (bool) config('payments.stripe_enabled')
            && is_string($this->config('api_key'))
            && $this->config('api_key') !== '';
    }

    public function createCustomer(User $user, string $paymentToken): string
    {
        $response = $this->post('/v1/customers', [
            'email' => $user->email,
            'name' => $user->name,
            'payment_method' => $paymentToken,
            'invoice_settings' => ['default_payment_method' => $paymentToken],
            'metadata' => ['vigil_user_id' => (string) $user->id],
        ]);

        $id = $response['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new PaymentException('Stripe returned no customer id.');
        }

        return $id;
    }

    public function createSubscription(string $customerId, string $tier, string $interval): GatewaySubscription
    {
        $trial = (int) config('payments.trial_days');

        $response = $this->post('/v1/subscriptions', array_filter([
            'customer' => $customerId,
            'items' => [['price' => $this->plans->gatewayPlanId($tier, $interval)]],
            'trial_period_days' => $trial > 0 ? $trial : null,
            'payment_behavior' => 'error_if_incomplete',
        ], fn ($v) => $v !== null));

        return $this->toSubscription($response, $tier, $interval);
    }

    public function changePlan(string $subscriptionId, string $tier, string $interval): GatewaySubscription
    {
        // The item has to be named to be replaced, and only Stripe knows
        // its id, so the current subscription is read first.
        $current = $this->get('/v1/subscriptions/'.rawurlencode($subscriptionId));

        $itemId = $current['items']['data'][0]['id'] ?? null;

        if (! is_string($itemId)) {
            throw new PaymentException('Stripe subscription has no line to change.');
        }

        $response = $this->post('/v1/subscriptions/'.rawurlencode($subscriptionId), [
            'items' => [[
                'id' => $itemId,
                'price' => $this->plans->gatewayPlanId($tier, $interval),
            ]],
            // The customer is charged or credited the difference now.
            // Silently not prorating would be taking money for a month
            // of Pro that was never served.
            'proration_behavior' => 'always_invoice',
        ]);

        return $this->toSubscription($response, $tier, $interval);
    }

    public function cancelAtPeriodEnd(string $subscriptionId): void
    {
        $this->post('/v1/subscriptions/'.rawurlencode($subscriptionId), [
            'cancel_at_period_end' => 'true',
        ]);
    }

    public function resume(string $subscriptionId): void
    {
        $this->post('/v1/subscriptions/'.rawurlencode($subscriptionId), [
            'cancel_at_period_end' => 'false',
        ]);
    }

    public function refund(string $chargeId, int $amountCents): void
    {
        $this->post('/v1/refunds', [
            // Stripe takes the smallest currency unit, which is what
            // this product carries anyway.
            'charge' => $chargeId,
            'amount' => (string) $amountCents,
        ]);
    }

    public function hostsBillingPortal(): bool
    {
        return true;
    }

    public function customerPortalUrl(string $customerId): ?string
    {
        $response = $this->post('/v1/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => route('account'),
        ]);

        $url = $response['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function handleWebhook(Request $request): ?WebhookEvent
    {
        $raw = $request->getContent();

        $this->verifySignature($request->header('Stripe-Signature'), $raw);

        $body = json_decode($raw, true);

        if (! is_array($body)) {
            throw PaymentException::badSignature('body was not JSON');
        }

        $id = $body['id'] ?? null;
        $type = $body['type'] ?? null;

        if (! is_string($id) || ! is_string($type)) {
            throw new PaymentException('Stripe webhook had no id or type.');
        }

        return (new StripeEventTranslator)->translate($id, $type, (array) ($body['data']['object'] ?? []), $body);
    }

    /**
     * Verify `Stripe-Signature`, in constant time, over the raw bytes.
     *
     * Header is `t=<unix>,v1=<hex>` and the signed string is the
     * timestamp, a dot, then the body. Unlike NMI's, the `t` here really
     * is a timestamp, so a replay window is enforceable and is enforced.
     *
     * @throws PaymentException
     */
    private function verifySignature(?string $header, string $raw): void
    {
        $key = $this->config('webhook_key');

        if (! is_string($key) || $key === '') {
            throw PaymentException::badSignature('no signing secret is configured');
        }

        if (! is_string($header) || $header === '') {
            throw PaymentException::badSignature('header missing');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, null);

            match ($k) {
                't' => $timestamp = (string) $v,
                'v1' => $signatures[] = strtolower((string) $v),
                default => null,
            };
        }

        if ($timestamp === null || $signatures === []) {
            throw PaymentException::badSignature('header was not t=,v1=');
        }

        $tolerance = (int) config('payments.webhook_tolerance_seconds');

        if ($tolerance > 0 && abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            throw PaymentException::badSignature('outside the replay window');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $key);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw PaymentException::badSignature('did not match');
    }

    /** @param  array<string, mixed>  $response */
    private function toSubscription(array $response, string $tier, string $interval): GatewaySubscription
    {
        $id = $response['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new PaymentException('Stripe returned no subscription id.');
        }

        return new GatewaySubscription(
            id: $id,
            tier: $tier,
            interval: $interval,
            periodEnd: $this->date($response['current_period_end'] ?? null),
            trialEndsAt: $this->date($response['trial_end'] ?? null),
            amountCents: PlanCatalogue::amountCents($tier, $interval),
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        // Stripe deals in unix seconds throughout.
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? CarbonImmutable::createFromTimestampUTC((int) $value)
            : null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->send('post', $path, $body);
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        return $this->send('get', $path);
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
            $request = Http::withToken((string) $this->config('api_key'))
                ->asForm()
                ->timeout(20)
                ->retry(2, 250, throw: false);

            $url = $this->config('base_url').$path;

            $response = $method === 'get'
                ? $request->get($url)
                : $request->post($url, $body);
        } catch (ConnectionException $e) {
            throw PaymentException::unreachable($e->getMessage());
        }

        $decoded = $response->json();
        $decoded = is_array($decoded) ? $decoded : [];

        if ($response->failed()) {
            // Without the request body: it carries a payment method id,
            // and anything in a log is in every backup of that log.
            Log::warning('Stripe call failed', [
                'path' => $path,
                'status' => $response->status(),
                'code' => $decoded['error']['code'] ?? null,
            ]);

            $detail = (string) ($decoded['error']['message'] ?? 'HTTP '.$response->status());

            throw ($decoded['error']['type'] ?? null) === 'card_error'
                ? PaymentException::declined($detail)
                : PaymentException::unreachable($detail);
        }

        return $decoded;
    }

    private function config(string $key): mixed
    {
        return config('payments.drivers.'.self::DRIVER.'.'.$key);
    }
}
