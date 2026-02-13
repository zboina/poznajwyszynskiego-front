<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(private Connection $connection) {}

    #[Route('/admin/uzytkownicy', name: 'app_admin_users')]
    public function users(): Response
    {
        $users = $this->connection->executeQuery(
            'SELECT u.id, u.email, u.name, u.roles, u.is_active, u.created_at, u.last_login_at
             FROM "user" u
             ORDER BY u.id'
        )->fetchAllAssociative();

        // Get view counts for the last 24h per user
        $viewCounts = $this->connection->executeQuery(
            "SELECT user_id, COUNT(DISTINCT document_id) AS cnt
             FROM document_views
             WHERE viewed_at > NOW() - INTERVAL '24 hours'
             GROUP BY user_id"
        )->fetchAllKeyValue();

        return $this->render('admin/users.html.twig', [
            'users' => $users,
            'viewCounts' => $viewCounts,
        ]);
    }

    #[Route('/admin/uzytkownicy/{id}/reset-views', name: 'app_admin_reset_views', methods: ['POST'])]
    public function resetViews(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('reset-views-' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Nieprawidłowy token CSRF.');
            return $this->redirectToRoute('app_admin_users');
        }

        $email = $this->connection->executeQuery(
            'SELECT email FROM "user" WHERE id = :id',
            ['id' => $id]
        )->fetchOne();

        if (!$email) {
            $this->addFlash('error', 'Nie znaleziono użytkownika.');
            return $this->redirectToRoute('app_admin_users');
        }

        $deleted = $this->connection->executeStatement(
            "DELETE FROM document_views WHERE user_id = :uid AND viewed_at > NOW() - INTERVAL '24 hours'",
            ['uid' => $id]
        );

        $this->addFlash('success', "Zresetowano limit dla {$email} ({$deleted} rekordów usunięto).");

        return $this->redirectToRoute('app_admin_users');
    }
}
