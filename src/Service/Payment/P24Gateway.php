<?php

namespace App\Service\Payment;

use App\Entity\Payment;
use App\Entity\User;
use App\Repository\PaymentRepository;
use App\Service\P24Service;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Przelewy24 jako bramka.
 *
 * Zasadnicza różnica wobec Stripe'a: P24 nie przenosi metadanych. Ich
 * powiadomienie zwraca tylko sessionId, orderId, kwotę i podpis — nie ma pola,
 * w którym dałoby się przekazać „komu nadać rolę i ile kredytów doliczyć".
 *
 * Dlatego intencję zapisujemy w naszej bazie ZANIM klient wyjdzie na bramkę, a
 * sessionId jest kluczem, po którym webhook ją odnajduje. To zresztą uczciwszy
 * układ niż u Stripe'a: nieopłacone próby zostawiają ślad i widać, ile osób
 * rozmyśliło się przy wyborze banku.
 */
class P24Gateway implements PaymentGateway
{
    public function __construct(
        private P24Service $p24,
        private PaymentRepository $payments,
        private UrlGeneratorInterface $urls,
    ) {}

    public function name(): string
    {
        return 'p24';
    }

    public function label(): string
    {
        return 'Przelewy24 — przelew, BLIK, karta';
    }

    public function isConfigured(): bool
    {
        return $this->p24->isConfigured();
    }

    public function createCheckout(
        User $user,
        int $amountGrosze,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $description,
    ): string {
        // Losowy, niepowtarzalny klucz transakcji. P24 odrzuca powtórzone
        // sessionId, a my potrzebujemy go jako identyfikatora intencji.
        $sessionId = bin2hex(random_bytes(16));

        $days = max(0, (int) ($metadata['period_days'] ?? 0));
        $months = max(1, (int) ($metadata['period_months'] ?? 12));

        // Zamiar czeka w bazie jako 'pending'. Rola i kredyty zostaną nadane
        // dopiero przez webhook, po potwierdzeniu wpłaty — nigdy tutaj.
        $payment = new Payment(
            $user->getId(),
            $amountGrosze,
            $metadata['granted_role'] ?? null,
            $days > 0 ? max(1, (int) ceil($days / 30)) : $months,
        );
        $payment->setProvider($this->name())
            ->setExternalId($sessionId)
            ->setPeriodDays($days)
            ->setMeta($metadata);

        $this->payments->save($payment);

        try {
            $token = $this->p24->register(
                $sessionId,
                $amountGrosze,
                $description,
                $user->getEmail(),
                // P24 zna jeden adres powrotu, nie dwa — klient wraca tu również
                // po rezygnacji. Stąd $cancelUrl nie ma tutaj zastosowania;
                // strona sukcesu i tak nie nadaje uprawnień, tylko dziękuje.
                $successUrl,
                $this->urls->generate('app_p24_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (\Throwable $e) {
            // Rejestracja padła — zamiar nigdy nie doczeka powiadomienia.
            // Oznaczamy go, żeby nie zaśmiecał listy „wiszących" płatności.
            $payment->markFailed();
            $this->payments->save($payment);
            throw $e;
        }

        return $this->p24->paymentUrl($token);
    }
}
