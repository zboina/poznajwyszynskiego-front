<?php

namespace App\Controller;

use App\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AccountController extends AbstractController
{
    public function __construct(private PaymentRepository $payments) {}

    #[Route('/moje-konto', name: 'app_account', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $payments = $this->payments->findByUser($user->getId());
        $totalGrosze = 0;
        foreach ($payments as $p) {
            if ($p->isPaid()) {
                $totalGrosze += $p->getAmount();
            }
        }

        return $this->render('account/index.html.twig', [
            'payments' => $payments,
            'totalGrosze' => $totalGrosze,
        ]);
    }
}
