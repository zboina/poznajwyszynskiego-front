<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses authentication for accounts that are inactive or have not confirmed
 * their e-mail address yet.
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }
        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('To konto jest nieaktywne.');
        }
        if (!$user->isVerified()) {
            throw new CustomUserMessageAccountStatusException('Potwierdź adres e-mail, zanim się zalogujesz. Sprawdź skrzynkę pocztową.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
