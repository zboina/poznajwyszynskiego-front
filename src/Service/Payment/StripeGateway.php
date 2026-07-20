<?php

namespace App\Service\Payment;

use App\Entity\User;
use App\Service\StripeService;

/**
 * Stripe jako bramka. Cienka przejściówka — całą robotę wykonuje istniejący
 * StripeService, którego celowo nie ruszamy: działa na produkcji i nie ma
 * powodu, by przebudowa płatności niosła ryzyko regresji po tej stronie.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(private StripeService $stripe) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Karta lub BLIK (Stripe)';
    }

    public function isConfigured(): bool
    {
        return $this->stripe->isConfigured();
    }

    public function createCheckout(
        User $user,
        int $amountGrosze,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $description,
    ): string {
        return $this->stripe->createCheckoutSession(
            $user,
            $amountGrosze,
            $successUrl,
            $cancelUrl,
            $metadata,
            $description,
        );
    }
}
