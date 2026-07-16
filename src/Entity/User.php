<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '"user"')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'is_verified', options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(name: 'verification_token', length: 64, nullable: true)]
    private ?string $verificationToken = null;

    #[ORM\Column(name: 'verification_expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeInterface $verificationExpiresAt = null;

    /** When the paid (DONATOR/VIP) access expires; null = no active paid access. */
    #[ORM\Column(name: 'access_expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeInterface $accessExpiresAt = null;

    /** The paid role currently granted (e.g. ROLE_DONATOR / ROLE_VIP), for downgrade on expiry. */
    #[ORM\Column(name: 'granted_role', length: 32, nullable: true)]
    private ?string $grantedRole = null;

    #[ORM\Column(name: 'stripe_customer_id', length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    /** Remaining AI-assistant questions (a donation tops this up; each query spends one). */
    #[ORM\Column(name: 'ai_credits', options: ['default' => 0])]
    private int $aiCredits = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'last_login_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeInterface $lastLoginAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;

        // Płatna rola wygasa wraz z oknem dostępu: po terminie nadana rola (np.
        // ROLE_VIP / ROLE_DONATOR) przestaje obowiązywać — traci moc wszędzie, gdzie
        // decyduje isGranted: biblioteka, darmowy eksport PDF, zwolnienie z ochrony
        // treści. Role z hierarchii (np. admin) nie mają grantedRole ani daty, więc
        // ich to nie dotyczy. Sam wpis w $this->roles zostaje — czyści go dopiero
        // faktyczny zakup lub porządkowanie danych; tu tylko przestaje być aktywny.
        if ($this->grantedRole !== null
            && $this->accessExpiresAt instanceof \DateTimeInterface
            && $this->accessExpiresAt < new \DateTimeImmutable()
        ) {
            $roles = array_values(array_filter($roles, fn ($r) => $r !== $this->grantedRole));
        }

        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    /** @param string[] $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));
        return $this;
    }

    public function addRole(string $role): static
    {
        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
        return $this;
    }

    public function removeRole(string $role): static
    {
        $this->roles = array_values(array_filter($this->roles, fn($r) => $r !== $role));
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getVerificationToken(): ?string
    {
        return $this->verificationToken;
    }

    public function setVerificationToken(?string $token): static
    {
        $this->verificationToken = $token;
        return $this;
    }

    public function getVerificationExpiresAt(): ?\DateTimeInterface
    {
        return $this->verificationExpiresAt;
    }

    public function setVerificationExpiresAt(?\DateTimeInterface $at): static
    {
        $this->verificationExpiresAt = $at;
        return $this;
    }

    public function getAccessExpiresAt(): ?\DateTimeInterface
    {
        return $this->accessExpiresAt;
    }

    public function setAccessExpiresAt(?\DateTimeInterface $at): static
    {
        $this->accessExpiresAt = $at;
        return $this;
    }

    public function getGrantedRole(): ?string
    {
        return $this->grantedRole;
    }

    public function setGrantedRole(?string $role): static
    {
        $this->grantedRole = $role;
        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $id): static
    {
        $this->stripeCustomerId = $id;
        return $this;
    }

    public function getAiCredits(): int
    {
        return $this->aiCredits;
    }

    public function addAiCredits(int $n): static
    {
        $this->aiCredits = max(0, $this->aiCredits + $n);
        return $this;
    }

    public function spendAiCredit(): static
    {
        $this->aiCredits = max(0, $this->aiCredits - 1);
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $at): static
    {
        $this->createdAt = $at;
        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeInterface
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeInterface $at): static
    {
        $this->lastLoginAt = $at;
        return $this;
    }

    public function eraseCredentials(): void
    {
    }
}
