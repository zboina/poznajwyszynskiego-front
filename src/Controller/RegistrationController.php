<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailVerificationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private EmailVerificationService $verifier,
        private LoggerInterface $logger,
        private string $registerPin = '',
    ) {}

    #[Route('/rejestracja', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        // Already logged in → nothing to do here.
        if ($this->getUser()) {
            return $this->redirectToRoute('app_search');
        }

        // PIN aktywny tylko gdy ustawiony w env (REGISTER_PIN) — wtedy rejestracja
        // wymaga podania kodu zaproszenia. Pusty PIN = rejestracja otwarta.
        $pinRequired = $this->registerPin !== '';

        $old = ['email' => '', 'name' => ''];

        if ($request->isMethod('POST')) {
            $email = mb_strtolower(trim((string) $request->request->get('email')));
            $name = trim((string) $request->request->get('name'));
            $password = (string) $request->request->get('password');
            $password2 = (string) $request->request->get('password2');
            $agree = (bool) $request->request->get('agree');
            $pin = trim((string) $request->request->get('pin'));
            $old = ['email' => $email, 'name' => $name];

            $errors = [];
            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'Sesja wygasła — spróbuj ponownie.';
            }
            if ($pinRequired && !hash_equals($this->registerPin, $pin)) {
                $errors[] = 'Nieprawidłowy kod PIN rejestracji.';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Podaj poprawny adres e-mail.';
            }
            if (mb_strlen($password) < 8) {
                $errors[] = 'Hasło musi mieć co najmniej 8 znaków.';
            }
            if ($password !== $password2) {
                $errors[] = 'Hasła nie są identyczne.';
            }
            if (!$agree) {
                $errors[] = 'Wymagana jest akceptacja regulaminu.';
            }
            if (!$errors && $this->users->findOneByEmail($email)) {
                $errors[] = 'Konto z tym adresem już istnieje. Możesz się zalogować.';
            }

            if ($errors) {
                return $this->render('registration/register.html.twig', ['errors' => $errors, 'old' => $old, 'pinRequired' => $pinRequired]);
            }

            $user = new User();
            $user->setEmail($email);
            $user->setName($name !== '' ? $name : null);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $user->setRoles([]); // getRoles() always adds ROLE_USER
            $user->setIsActive(true);
            $user->setIsVerified(false);
            $user->setCreatedAt(new \DateTimeImmutable());

            // Persist the account first, then send best-effort so a transient
            // SMTP failure never loses the account (the link can be re-sent).
            $this->verifier->assignToken($user);
            $this->users->save($user);
            try {
                $this->verifier->send($user);
            } catch (\Throwable $e) {
                // account exists, e-mail can be resent later — but log why it failed
                $this->logger->error('Wysyłka maila weryfikacyjnego nie powiodła się dla {email}: {err}', [
                    'email' => $user->getEmail(),
                    'err' => $e->getMessage(),
                ]);
            }

            return $this->redirectToRoute('app_register_check_email');
        }

        return $this->render('registration/register.html.twig', ['errors' => [], 'old' => $old, 'pinRequired' => $pinRequired]);
    }

    #[Route('/rejestracja/sprawdz-email', name: 'app_register_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('registration/check_email.html.twig');
    }

    #[Route('/rejestracja/potwierdz/{token}', name: 'app_verify_email', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function verify(string $token): Response
    {
        $user = $this->verifier->confirm($token);
        if (!$user) {
            $this->addFlash('error', 'Link weryfikacyjny jest nieprawidłowy lub wygasł. Zarejestruj się ponownie lub poproś o nowy link.');
            return $this->redirectToRoute('app_login');
        }
        $this->users->save($user);
        $this->addFlash('success', 'Adres e-mail potwierdzony. Możesz się teraz zalogować.');
        return $this->redirectToRoute('app_login');
    }
}
