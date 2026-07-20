<?php

namespace App\Service;

use App\Entity\User;

/**
 * Nadanie tego, co kupiono: kredyty AI, rola i okno czasowego dostępu.
 *
 * Logika mieszkała w StripeWebhookController; wyprowadzona tutaj, bo webhook
 * Przelewy24 musi liczyć okres dostępu DOKŁADNIE tak samo. Rozjazd między
 * dwiema kopiami tej reguły byłby cichy i kosztowny — czytelnik zapłaciłby
 * tyle samo, a dostał inny termin ważności zależnie od wybranej bramki.
 *
 * Klasa nie zapisuje niczego do bazy — zmienia tylko encję User. Flush należy
 * do wywołującego, razem z jego własnym zapisem płatności, w jednej transakcji.
 */
class AccessGranter
{
    /**
     * @param int $credits kredyty AI do doliczenia (0 przy dostępie VIP)
     * @param string $role rola nadawana na czas okresu
     * @param int $days okres w dniach; gdy 0 — liczony z $months
     * @param int $months okres w miesiącach (domyślna ścieżka darowizn)
     *
     * @return \DateTimeImmutable nowa data wygaśnięcia dostępu
     */
    public function apply(User $user, int $credits, string $role, int $days, int $months): \DateTimeImmutable
    {
        $user->addAiCredits(max(0, $credits));

        $prevRole = $user->getGrantedRole();
        $user->addRole($role);
        $user->setGrantedRole($role);

        // Odnowienie TEJ SAMEJ roli dolicza się do istniejącego okna; zmiana poziomu
        // (np. donator kupuje VIP na 30 dni) liczy się od teraz, żeby nie odziedziczyć
        // dalekiej daty poprzedniej roli i nie rozdąć krótkiego dostępu VIP na lata.
        $base = $user->getAccessExpiresAt();
        $sameTier = $prevRole === $role;
        $from = ($sameTier && $base instanceof \DateTimeInterface && $base > new \DateTimeImmutable())
            ? \DateTimeImmutable::createFromInterface($base)
            : new \DateTimeImmutable();

        $expires = $days > 0
            ? $from->modify("+{$days} days")
            : $from->modify('+' . max(1, $months) . ' months');

        $user->setAccessExpiresAt($expires);

        return $expires;
    }
}
