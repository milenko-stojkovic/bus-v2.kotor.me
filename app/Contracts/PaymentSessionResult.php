<?php

namespace App\Contracts;

/**
 * Rezultat sync poziva createSession – za redirect na bank payment page.
 * Ako success === true, payment_url je set; inače error_message (gateway spor/nedostupan).
 */
final readonly class PaymentSessionResult
{
    public function __construct(
        public bool $success,
        public ?string $paymentUrl = null,
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
        public ?string $failureReason = null,
        public ?string $gatewayErrorCode = null,
    ) {}

    public static function ok(string $paymentUrl): self
    {
        return new self(true, $paymentUrl, null);
    }

    public static function unavailable(
        ?string $message = null,
        ?int $httpStatus = null,
        ?string $failureReason = null,
        ?string $gatewayErrorCode = null,
    ): self {
        return new self(
            false,
            null,
            $message ?? 'Payment temporarily unavailable.',
            $httpStatus,
            $failureReason,
            $gatewayErrorCode,
        );
    }
}
