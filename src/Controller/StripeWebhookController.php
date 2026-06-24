<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use App\Service\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StripeWebhookController extends AbstractController
{
    public function __construct(
        private StripeService $stripe,
        private UserRepository $users,
        private PaymentRepository $payments,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    #[Route('/webhook/stripe', name: 'app_stripe_webhook', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        try {
            $event = $this->stripe->verifyWebhook(
                $request->getContent(),
                $request->headers->get('Stripe-Signature'),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Odrzucony webhook Stripe: {err}', ['err' => $e->getMessage()]);
            return new Response('invalid signature', 400);
        }

        if (($event['type'] ?? '') !== 'checkout.session.completed') {
            return new Response('ignored', 200); // not our concern
        }

        $session = $event['data']['object'] ?? [];
        if (($session['payment_status'] ?? '') !== 'paid') {
            return new Response('not paid', 200);
        }

        $sessionId = (string) ($session['id'] ?? '');
        // Idempotency: a retried webhook must not grant twice.
        if ($sessionId !== '' && $this->payments->findOneByExternalId($sessionId)) {
            return new Response('already processed', 200);
        }

        $meta = $session['metadata'] ?? [];
        $userId = (int) ($meta['user_id'] ?? 0);
        $credits = (int) ($meta['credits'] ?? 0);
        $role = (string) ($meta['granted_role'] ?? 'ROLE_DONATOR');
        $months = max(1, (int) ($meta['period_months'] ?? 12));
        $amount = (int) ($session['amount_total'] ?? 0);

        $user = $userId ? $this->users->find($userId) : null;
        if (!$user) {
            // Unrecoverable — ack so Stripe stops retrying, but record the problem.
            $this->logger->error('Webhook Stripe: brak użytkownika {uid} (sesja {sid})', ['uid' => $userId, 'sid' => $sessionId]);
            return new Response('user not found', 200);
        }

        // Grant: top up AI credits, (re)grant role, extend access window.
        $user->addAiCredits($credits);
        $user->addRole($role);
        $user->setGrantedRole($role);

        $base = $user->getAccessExpiresAt();
        $from = ($base instanceof \DateTimeInterface && $base > new \DateTimeImmutable())
            ? \DateTimeImmutable::createFromInterface($base)
            : new \DateTimeImmutable();
        $user->setAccessExpiresAt($from->modify("+{$months} months"));

        $payment = new Payment($user->getId(), $amount, $role, $months);
        $payment->setExternalId($sessionId)->setProvider('stripe')->markPaid();

        $this->em->persist($payment);
        $this->em->flush();

        $this->logger->info('Darowizna zaksięgowana: user={uid} +{cr} kredytów, rola {role} do {exp}', [
            'uid' => $user->getId(), 'cr' => $credits, 'role' => $role,
            'exp' => $user->getAccessExpiresAt()?->format('Y-m-d'),
        ]);

        return new Response('ok', 200);
    }
}
