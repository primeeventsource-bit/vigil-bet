<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Services\Payments\Nmi\NmiGateway;
use App\Services\Payments\Stripe\StripeGateway;
use Illuminate\Support\Facades\Log;

/**
 * Turns PAYMENT_DRIVER into a gateway.
 *
 * The whole of §13's "switching PAYMENT_DRIVER between nmi and stripe
 * requires no code changes": this is the only place either class is
 * named, and everything else in the application asks the container for
 * the interface.
 */
final class PaymentDrivers
{
    /** @var array<string, class-string<PaymentGateway>> */
    private const DRIVERS = [
        'nmi' => NmiGateway::class,
        'stripe' => StripeGateway::class,
        'none' => UnconfiguredGateway::class,
    ];

    public static function make(?string $name = null): PaymentGateway
    {
        $name = $name ?? (string) config('payments.driver', 'none');

        $class = self::DRIVERS[$name] ?? null;

        if ($class === null) {
            /*
             * An unknown driver falls back to refusing everything rather
             * than throwing on boot. A typo in an environment variable
             * should take the checkout offline, not the whole site - and
             * it should say so in the log rather than only in a 500.
             */
            Log::error('Unknown PAYMENT_DRIVER; no payments will be taken.', ['driver' => $name]);

            return new UnconfiguredGateway;
        }

        return new $class;
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::DRIVERS);
    }
}
