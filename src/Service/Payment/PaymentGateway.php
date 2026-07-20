<?php

namespace App\Service\Payment;

use App\Entity\User;

/**
 * Bramka płatnicza widziana oczami kontrolera: „załóż płatność i powiedz, dokąd
 * odesłać klienta". Reszta — tokeny, podpisy, webhooki — to sprawa implementacji.
 *
 * Dzięki temu kontrolery nie znają Stripe'a ani P24 z nazwy i zmiana dostawcy
 * nie wymaga dotykania logiki darowizn ani ról.
 */
interface PaymentGateway
{
    /** Krótki identyfikator zapisywany w payment.provider: 'stripe' | 'p24'. */
    public function name(): string;

    /** Nazwa dla czytelnika, pokazywana przy wyborze metody płatności. */
    public function label(): string;

    /** Czy bramka ma komplet poświadczeń i można jej użyć. */
    public function isConfigured(): bool;

    /**
     * Zakłada płatność i zwraca adres, pod który należy przekierować klienta.
     *
     * @param int                  $amountGrosze kwota w groszach
     * @param array<string,string> $metadata     intencja: user_id, credits,
     *                                           granted_role, period_days/period_months
     *
     * @throws \RuntimeException gdy dostawca odmówi założenia płatności
     */
    public function createCheckout(
        User $user,
        int $amountGrosze,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $description,
    ): string;
}
