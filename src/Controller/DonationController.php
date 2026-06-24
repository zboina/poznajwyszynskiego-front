<?php

namespace App\Controller;

use App\Service\StripeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Pay-what-you-want donations. The amount buys a pool of AI-assistant questions
 * (CREDITS_PER_PLN per złoty) and grants DONATOR access for PERIOD_MONTHS.
 * Credits/role are applied by the Stripe webhook, never on the client redirect.
 */
class DonationController extends AbstractController
{
    public const MIN_PLN = 10;
    public const CREDITS_PER_PLN = 25;
    public const PERIOD_MONTHS = 12;
    public const GRANTED_ROLE = 'ROLE_DONATOR';

    public function __construct(private StripeService $stripe) {}

    public static function creditsFor(int $amountGrosze): int
    {
        return (int) round($amountGrosze / 100 * self::CREDITS_PER_PLN);
    }

    #[Route('/wesprzyj', name: 'app_donate', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('donation/index.html.twig', [
            'minPln' => self::MIN_PLN,
            'creditsPerPln' => self::CREDITS_PER_PLN,
            'periodMonths' => self::PERIOD_MONTHS,
            'configured' => $this->stripe->isConfigured(),
            'currentCredits' => $this->getUser() ? $this->getUser()->getAiCredits() : 0,
        ]);
    }

    #[Route('/wesprzyj/checkout', name: 'app_donate_checkout', methods: ['POST'])]
    public function checkout(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->isCsrfTokenValid('donate', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Sesja wygasła — spróbuj ponownie.');
            return $this->redirectToRoute('app_donate');
        }
        if (!$this->stripe->isConfigured()) {
            $this->addFlash('error', 'Płatności są chwilowo niedostępne.');
            return $this->redirectToRoute('app_donate');
        }

        // Parse "30" / "30,50" / "30.50" → grosze.
        $raw = str_replace([' ', ','], ['', '.'], (string) $request->request->get('amount'));
        $pln = is_numeric($raw) ? (float) $raw : 0.0;
        $amountGrosze = (int) round($pln * 100);

        if ($amountGrosze < self::MIN_PLN * 100) {
            $this->addFlash('error', sprintf('Minimalna darowizna to %d zł.', self::MIN_PLN));
            return $this->redirectToRoute('app_donate');
        }

        $credits = self::creditsFor($amountGrosze);
        $metadata = [
            'user_id' => (string) $user->getId(),
            'credits' => (string) $credits,
            'granted_role' => self::GRANTED_ROLE,
            'period_months' => (string) self::PERIOD_MONTHS,
        ];

        try {
            $url = $this->stripe->createCheckoutSession(
                $user,
                $amountGrosze,
                $this->generateUrl('app_donate_success', [], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('app_donate_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL),
                $metadata,
            );
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Nie udało się rozpocząć płatności. Spróbuj ponownie później.');
            return $this->redirectToRoute('app_donate');
        }

        return $this->redirect($url);
    }

    #[Route('/wesprzyj/dziekujemy', name: 'app_donate_success', methods: ['GET'])]
    public function success(): Response
    {
        return $this->render('donation/success.html.twig');
    }

    #[Route('/wesprzyj/anulowano', name: 'app_donate_cancel', methods: ['GET'])]
    public function cancel(): Response
    {
        return $this->render('donation/cancel.html.twig');
    }
}
