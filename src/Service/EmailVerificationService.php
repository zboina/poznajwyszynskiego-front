<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Lightweight e-mail verification: a random token stored on the user with a
 * 24h expiry, e-mailed as a confirmation link. No extra bundle required.
 */
class EmailVerificationService
{
    private const FROM_EMAIL = 'rejestracja@poznajwyszynskiego.pl';
    private const FROM_NAME = 'Dzieła Zebrane kard. Wyszyńskiego';
    private const TTL_HOURS = 24;

    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $router,
        private LoggerInterface $logger,
        private UserRepository $users,
    ) {}

    /**
     * Assign a fresh verification token + expiry to the user (caller must flush).
     */
    public function assignToken(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $user->setVerificationToken($token);
        $user->setVerificationExpiresAt(new \DateTimeImmutable('+' . self::TTL_HOURS . ' hours'));
        return $token;
    }

    /**
     * Send the verification e-mail for the token already stored on the user.
     * Throwing is the caller's concern — registration persists the account first
     * and treats sending as best-effort so SMTP hiccups don't lose the account.
     */
    public function send(User $user): void
    {
        $token = $user->getVerificationToken();
        if (!$token) {
            return;
        }

        $url = $this->router->generate(
            'app_verify_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Visible in logs so the flow is testable / debuggable.
        $this->logger->info('Link weryfikacyjny dla {email}: {url}', ['email' => $user->getEmail(), 'url' => $url]);

        $email = (new TemplatedEmail())
            ->from(new Address(self::FROM_EMAIL, self::FROM_NAME))
            ->to($user->getEmail())
            ->subject('Potwierdź rejestrację — Dzieła Zebrane kard. Wyszyńskiego')
            ->htmlTemplate('email/verification.html.twig')
            ->context(['url' => $url, 'name' => $user->getName() ?: '', 'ttlHours' => self::TTL_HOURS]);

        $this->mailer->send($email);
    }

    /** Convenience: assign a token and send in one go. */
    public function sendVerification(User $user): void
    {
        $this->assignToken($user);
        $this->send($user);
    }

    /**
     * Validate the token; on success mark the user verified and clear the token.
     * @return User|null the verified user, or null if token is invalid/expired
     */
    public function confirm(string $token): ?User
    {
        $user = $this->users->findOneBy(['verificationToken' => $token]);
        if (!$user) {
            return null;
        }
        $exp = $user->getVerificationExpiresAt();
        if ($exp !== null && $exp < new \DateTimeImmutable()) {
            return null;
        }
        $user->setIsVerified(true);
        $user->setVerificationToken(null);
        $user->setVerificationExpiresAt(null);
        return $user;
    }
}
