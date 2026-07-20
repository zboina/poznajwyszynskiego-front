<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use App\Service\AccessGranter;
use App\Service\P24Service;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Powiadomienie o wpłacie z Przelewy24 (urlStatus).
 *
 * Przebieg, w tej kolejności — każdy krok jest z innego powodu konieczny:
 *
 *  1. PODPIS. Powiadomienie przychodzi z internetu, bez sesji i bez logowania.
 *     Podpis liczony naszym kluczem CRC jest jedynym dowodem, że to naprawdę
 *     P24. Adresy IP nadawcy pomijamy świadomie — P24 je zmieniało, a różne
 *     źródła podają dziś sprzeczne zakresy, więc byłaby to krucha bariera.
 *  2. ZAMIAR. Odnajdujemy zapisaną przed przekierowaniem intencję po sessionId.
 *     Bez niej nie wiemy, komu i co nadać — P24 tego nie przenosi.
 *  3. KWOTA. Niedopłata nie kupuje dostępu.
 *  4. VERIFY. Dopóki nie potwierdzimy transakcji, P24 trzyma środki jako
 *     zaliczkę klienta i nam ich nie przekaże. Krok bez widocznego błędu w
 *     razie pominięcia — pieniądze po prostu nie docierają.
 *  5. NADANIE. Dopiero teraz rola i kredyty.
 *
 * Kody odpowiedzi sterują ponowieniami: P24 powtarza powiadomienie po 3, 5, 15,
 * 30, 60, 150 i 450 minutach, dopóki nie dostanie 2xx. Dlatego 200 zwracamy
 * także dla sytuacji nieodwracalnych (zdublowane, nieznany zamiar) — ponawianie
 * ich niczego nie naprawi. Kod 500 zostawiamy na awarie przejściowe, gdzie
 * kolejna próba ma sens.
 */
class P24WebhookController extends AbstractController
{
    public function __construct(
        private P24Service $p24,
        private UserRepository $users,
        private PaymentRepository $payments,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private AccessGranter $granter,
    ) {}

    #[Route('/webhook/p24', name: 'app_p24_webhook', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        // Nieskonfigurowana bramka nie ma prawa niczego przyjmować — patrz
        // P24Service::verifyNotificationSign(). Sprawdzamy też tutaj, żeby
        // odpowiedzieć jednoznacznie, zamiast udawać zły podpis.
        if (!$this->p24->isConfigured()) {
            return new Response('gateway not configured', 503);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new Response('invalid payload', 400);
        }

        // ── 1. Podpis ──
        if (!$this->p24->verifyNotificationSign($data)) {
            $this->logger->warning('Odrzucone powiadomienie P24: zły podpis (sesja {sid})', [
                'sid' => (string) ($data['sessionId'] ?? '?'),
            ]);
            return new Response('invalid signature', 400);
        }

        $sessionId = (string) ($data['sessionId'] ?? '');
        $orderId = (int) ($data['orderId'] ?? 0);
        $amount = (int) ($data['amount'] ?? 0);
        $currency = (string) ($data['currency'] ?? 'PLN');

        // ── 2. Zamiar ──
        $payment = $sessionId !== '' ? $this->payments->findOneByExternalId($sessionId) : null;
        if (!$payment instanceof Payment) {
            $this->logger->error('Powiadomienie P24 bez zapisanego zamiaru (sesja {sid})', ['sid' => $sessionId]);
            return new Response('unknown session', 200);
        }
        if ($payment->isPaid()) {
            return new Response('already processed', 200); // ponowienie po naszym 200
        }

        // ── 3. Kwota ──
        // originAmount to kwota, na jaką transakcja została założona; amount to
        // kwota faktycznie wpłacona. Rozjazd oznacza niedopłatę — nie kupuje dostępu.
        if ($amount < $payment->getAmount()) {
            $payment->markFailed();
            $this->em->flush();
            $this->logger->warning('Niedopłata P24: {got} gr zamiast {want} gr (sesja {sid})', [
                'got' => $amount, 'want' => $payment->getAmount(), 'sid' => $sessionId,
            ]);
            return new Response('amount mismatch', 200);
        }

        // ── 4. Potwierdzenie transakcji u P24 ──
        try {
            $this->p24->verify($sessionId, $orderId, $amount, $currency);
        } catch (\Throwable $e) {
            // Może być przejściowe (sieć, chwilowa awaria P24) — 500 prosi o ponowienie.
            $this->logger->error('Weryfikacja P24 nie powiodła się (sesja {sid}): {err}', [
                'sid' => $sessionId, 'err' => $e->getMessage(),
            ]);
            return new Response('verification failed', 500);
        }

        // ── 5. Nadanie dostępu ──
        $meta = $payment->getMeta();
        $userId = (int) ($meta['user_id'] ?? 0);
        $user = $userId ? $this->users->find($userId) : null;
        if (!$user) {
            $this->logger->error('Powiadomienie P24: brak użytkownika {uid} (sesja {sid})', [
                'uid' => $userId, 'sid' => $sessionId,
            ]);
            return new Response('user not found', 200);
        }

        $credits = (int) ($meta['credits'] ?? 0);
        $role = (string) ($meta['granted_role'] ?? 'ROLE_DONATOR');
        $days = max(0, (int) ($meta['period_days'] ?? 0));
        $months = max(1, (int) ($meta['period_months'] ?? 12));

        $expires = $this->granter->apply($user, $credits, $role, $days, $months);

        $payment->setOrderId($orderId)->markPaid();
        $this->em->flush();

        $this->logger->info('Wpłata P24 zaksięgowana: user={uid} +{cr} kredytów, rola {role} do {exp}', [
            'uid' => $user->getId(), 'cr' => $credits, 'role' => $role,
            'exp' => $expires->format('Y-m-d'),
        ]);

        return new Response('ok', 200);
    }
}
