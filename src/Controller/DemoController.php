<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\SettingsService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class DemoController extends AbstractController
{
    private const DEMO_USERS = [
        'user' => [
            'email' => 'demo-user@poznajwyszynskiego.pl',
            'name' => 'Demo Użytkownik',
            'roles' => ['ROLE_USER'],
        ],
        'donator' => [
            'email' => 'demo-donator@poznajwyszynskiego.pl',
            'name' => 'Demo Donator',
            'roles' => ['ROLE_DONATOR'],
        ],
        'vip' => [
            'email' => 'demo-vip@poznajwyszynskiego.pl',
            'name' => 'Demo VIP',
            'roles' => ['ROLE_VIP'],
        ],
    ];

    public function __construct(
        private SettingsService $settings,
        private Connection $connection,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $em,
    ) {}

    #[Route('/demo/verify', name: 'app_demo_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        if (!$this->settings->isDemoEnabled()) {
            return new JsonResponse(['ok' => false, 'error' => 'Demo wyłączone'], 403);
        }

        $password = $request->request->get('password', '');
        $correct = $this->settings->getDemoPassword();

        if ($password === $correct && $correct !== '') {
            $request->getSession()->set('demo_verified', true);
            return new JsonResponse(['ok' => true]);
        }

        return new JsonResponse(['ok' => false, 'error' => 'Nieprawidłowe hasło'], 401);
    }

    #[Route('/demo', name: 'app_demo_select')]
    public function select(Request $request): Response
    {
        $session = $request->getSession();
        if (!$session->get('demo_verified')) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('demo/select.html.twig', [
            'currentRole' => $session->get('demo_role', null),
        ]);
    }

    #[Route('/demo/activate/{role}', name: 'app_demo_activate', requirements: ['role' => 'guest|user|donator|vip'])]
    public function activate(
        string $role,
        Request $request,
        Security $security,
    ): Response {
        $session = $request->getSession();
        if (!$session->get('demo_verified')) {
            return $this->redirectToRoute('app_login');
        }

        $session->set('demo_role', $role);

        if ($role === 'guest') {
            // Logout current demo user, keep demo session flags
            $security->logout(false);
            $request->getSession()->set('demo_verified', true);
            $request->getSession()->set('demo_role', 'guest');
            return $this->redirectToRoute('app_search');
        }

        // Ensure demo user exists in DB
        $userConfig = self::DEMO_USERS[$role];
        $this->ensureDemoUser($userConfig['email'], $userConfig['name'], $userConfig['roles']);

        // Load user entity from DB
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $userConfig['email']]);

        if (!$user) {
            $this->addFlash('danger', 'Nie udało się utworzyć konta demo.');
            return $this->redirectToRoute('app_demo_select');
        }

        // Programmatic login via Security helper
        $security->login($user, 'form_login', 'main');

        return $this->redirectToRoute('app_search');
    }

    #[Route('/demo/exit', name: 'app_demo_exit')]
    public function exit(Request $request): Response
    {
        $session = $request->getSession();
        $session->remove('demo_verified');
        $session->remove('demo_role');

        return $this->redirectToRoute('app_logout');
    }

    private function ensureDemoUser(string $email, string $name, array $roles): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT id FROM "user" WHERE email = ?',
            [$email]
        );

        if ($exists) {
            // Update roles in case they changed
            $this->connection->executeStatement(
                'UPDATE "user" SET roles = ?, name = ?, is_active = true WHERE email = ?',
                [json_encode($roles), $name, $email]
            );
            return;
        }

        // Create demo user with random password (never used for manual login)
        $tempUser = new User();
        $hashedPassword = $this->hasher->hashPassword($tempUser, bin2hex(random_bytes(16)));

        $this->connection->executeStatement(
            'INSERT INTO "user" (email, name, password, roles, is_active, created_at) VALUES (?, ?, ?, ?, true, NOW())',
            [$email, $name, $hashedPassword, json_encode($roles)]
        );
    }
}
