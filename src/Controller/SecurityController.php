<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(private Connection $connection) {}

    #[Route('/logowanie', name: 'app_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_search');
        }

        // Login page is for guests — always show published-only stats
        $pf = "JOIN volumes v ON v.id = d.volume_id AND v.status = 'opublikowany'";
        $totalDocs = (int) $this->connection->executeQuery("SELECT COUNT(*) FROM documents d {$pf}")->fetchOne();
        $totalWords = (int) $this->connection->executeQuery("SELECT COALESCE(SUM(d.words_count),0) FROM documents d {$pf}")->fetchOne();
        $totalVolumes = (int) $this->connection->executeQuery("SELECT COUNT(*) FROM volumes WHERE status = 'opublikowany'")->fetchOne();
        $totalChars = (int) $this->connection->executeQuery("SELECT COALESCE(SUM(LENGTH(d.content)),0) FROM documents d {$pf}")->fetchOne();

        return $this->render('security/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error' => $authUtils->getLastAuthenticationError(),
            'totalDocs' => $totalDocs,
            'totalWords' => $totalWords,
            'totalVolumes' => $totalVolumes,
            'totalChars' => $totalChars,
        ]);
    }

    #[Route('/wyloguj', name: 'app_logout')]
    public function logout(): void
    {
    }
}
