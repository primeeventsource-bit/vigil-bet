<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

/**
 * A processor refused, or could not be reached.
 *
 * Carries a message safe to show a customer separately from the one
 * written to the log. "Declined" is what the customer needs; the
 * processor's response code is what support needs; and the two must not
 * be the same string, because processor responses sometimes quote the
 * card.
 */
class PaymentException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $customerMessage = 'That payment could not be completed. No money has moved.',
    ) {
        parent::__construct($message);
    }

    public function forCustomer(): string
    {
        return $this->customerMessage;
    }

    public static function declined(string $detail): self
    {
        return new self('Declined: '.$detail, 'That card was declined. Nothing has been charged.');
    }

    public static function unreachable(string $detail): self
    {
        return new self(
            'Processor unreachable: '.$detail,
            'We could not reach the payment processor. Nothing has been charged — please try again shortly.',
        );
    }

    public static function badSignature(string $detail): self
    {
        return new self('Webhook signature did not verify: '.$detail);
    }

    public static function notConfigured(string $driver): self
    {
        return new self(
            "The {$driver} driver has no credentials configured.",
            'Subscriptions are not available right now.',
        );
    }
}
