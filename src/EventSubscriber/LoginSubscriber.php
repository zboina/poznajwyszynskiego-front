<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Zapisuje datę ostatniego logowania użytkownika serwisu.
 * Bez tego pole `last_login_at` nigdy się nie aktualizuje przy logowaniu na froncie.
 */
class LoginSubscriber implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $managed = $this->em->getRepository(User::class)->find($user->getId());
        if ($managed) {
            $managed->setLastLoginAt(new \DateTimeImmutable());
            $this->em->flush();
        }
    }
}
