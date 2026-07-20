<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\AccessGranter;
use PHPUnit\Framework\TestCase;

/**
 * Reguła liczenia okresu dostępu została wyjęta ze StripeWebhookController, żeby
 * webhook Przelewy24 liczył go identycznie. Te testy pilnują, by przenosiny
 * niczego nie zmieniły — i by przyszła zmiana w jednej bramce nie rozjechała drugiej.
 */
class AccessGranterTest extends TestCase
{
    private function user(?string $role = null, ?string $expiresAt = null): User
    {
        $u = new User();
        $u->setEmail('czytelnik@example.test');
        if ($role !== null) {
            $u->setGrantedRole($role);
        }
        if ($expiresAt !== null) {
            $u->setAccessExpiresAt(new \DateTimeImmutable($expiresAt));
        }
        return $u;
    }

    public function testCreditsAreAddedToExistingPool(): void
    {
        $user = $this->user();
        $user->addAiCredits(40);

        (new AccessGranter())->apply($user, 250, 'ROLE_DONATOR', 0, 12);

        self::assertSame(290, $user->getAiCredits());
    }

    public function testRoleIsGranted(): void
    {
        $user = $this->user();

        (new AccessGranter())->apply($user, 0, 'ROLE_VIP', 30, 12);

        self::assertContains('ROLE_VIP', $user->getRoles());
        self::assertSame('ROLE_VIP', $user->getGrantedRole());
    }

    public function testRenewingSameRoleExtendsExistingWindow(): void
    {
        // Czytelnik ma VIP-a jeszcze przez 10 dni i dokupuje kolejne 30.
        // Powinien mieć 40, nie 30 — inaczej odnowienie „z zapasem" karałoby
        // za to, że nie czekał do ostatniego dnia.
        $future = (new \DateTimeImmutable())->modify('+10 days');
        $user = $this->user('ROLE_VIP', $future->format('Y-m-d H:i:s'));

        $expires = (new AccessGranter())->apply($user, 0, 'ROLE_VIP', 30, 12);

        $days = (int) (new \DateTimeImmutable())->diff($expires)->days;
        self::assertGreaterThanOrEqual(39, $days);
        self::assertLessThanOrEqual(40, $days);
    }

    public function testChangingTierStartsFromNow(): void
    {
        // Donator z dostępem na rok kupuje VIP-a na 30 dni. VIP ma trwać 30 dni,
        // a nie odziedziczyć dalekiej daty donatora i rozdąć się na rok.
        $user = $this->user('ROLE_DONATOR', (new \DateTimeImmutable())->modify('+300 days')->format('Y-m-d H:i:s'));

        $expires = (new AccessGranter())->apply($user, 0, 'ROLE_VIP', 30, 12);

        $days = (int) (new \DateTimeImmutable())->diff($expires)->days;
        self::assertGreaterThanOrEqual(29, $days);
        self::assertLessThanOrEqual(30, $days);
    }

    public function testExpiredWindowDoesNotCarryOver(): void
    {
        // Dostęp wygasł miesiąc temu — nowy okres liczy się od dziś, nie od
        // przeszłej daty (inaczej czytelnik kupiłby dostęp już wygasły).
        $user = $this->user('ROLE_VIP', (new \DateTimeImmutable())->modify('-30 days')->format('Y-m-d H:i:s'));

        $expires = (new AccessGranter())->apply($user, 0, 'ROLE_VIP', 30, 12);

        self::assertGreaterThan(new \DateTimeImmutable(), $expires);
        $days = (int) (new \DateTimeImmutable())->diff($expires)->days;
        self::assertGreaterThanOrEqual(29, $days);
    }

    public function testDaysTakePrecedenceOverMonths(): void
    {
        $user = $this->user();

        $expires = (new AccessGranter())->apply($user, 0, 'ROLE_VIP', 30, 12);

        $days = (int) (new \DateTimeImmutable())->diff($expires)->days;
        self::assertLessThanOrEqual(30, $days, 'Podane dni muszą wygrać z miesiącami.');
    }

    public function testMonthsUsedWhenNoDaysGiven(): void
    {
        $user = $this->user();

        $expires = (new AccessGranter())->apply($user, 250, 'ROLE_DONATOR', 0, 12);

        $days = (int) (new \DateTimeImmutable())->diff($expires)->days;
        self::assertGreaterThan(360, $days);
    }
}
