<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A donation that (once paid) grants a time-limited role.
 * amount is stored in grosze (integer) to avoid float issues.
 */
#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
#[ORM\Index(name: 'idx_payment_user', columns: ['user_id'])]
class Payment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id')]
    private int $userId;

    /** Amount in grosze (e.g. 3000 = 30,00 zł). */
    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency = 'pln';

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    /** 'stripe' | 'p24' */
    #[ORM\Column(length: 16)]
    private string $provider = 'stripe';

    /** Stripe Checkout Session / PaymentIntent id, albo sessionId po stronie P24. */
    #[ORM\Column(name: 'external_id', length: 255, nullable: true)]
    private ?string $externalId = null;

    /**
     * Numer transakcji P24 — znany dopiero z powiadomienia, wymagany do verify.
     * bigint, nie integer: P24 przekracza zakres 32-bitowy (patrz migracja).
     */
    #[ORM\Column(name: 'order_id', type: 'bigint', nullable: true)]
    private ?int $orderId = null;

    /**
     * Intencja płatności zapisana przed przekierowaniem: user_id, credits,
     * granted_role, period_days/period_months. Stripe oddaje to sam w webhooku,
     * P24 nie ma na to miejsca — więc czeka tutaj, odnajdywane po externalId.
     *
     * @var array<string,string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(name: 'granted_role', length: 32, nullable: true)]
    private ?string $grantedRole = null;

    #[ORM\Column(name: 'period_months', options: ['default' => 12])]
    private int $periodMonths = 12;

    /** Dokładny okres w dniach; 0 = liczony z periodMonths. */
    #[ORM\Column(name: 'period_days', options: ['default' => 0])]
    private int $periodDays = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'paid_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeInterface $paidAt = null;

    public function __construct(int $userId, int $amount, ?string $grantedRole, int $periodMonths = 12)
    {
        $this->userId = $userId;
        $this->amount = $amount;
        $this->grantedRole = $grantedRole;
        $this->periodMonths = $periodMonths;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getAmount(): int { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function isPaid(): bool { return $this->status === self::STATUS_PAID; }

    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): static { $this->provider = $provider; return $this; }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $id): static { $this->externalId = $id; return $this; }

    public function getOrderId(): ?int { return $this->orderId; }
    public function setOrderId(?int $orderId): static { $this->orderId = $orderId; return $this; }

    /** @return array<string,string> */
    public function getMeta(): array { return $this->meta ?? []; }
    /** @param array<string,string> $meta */
    public function setMeta(array $meta): static { $this->meta = $meta; return $this; }

    public function getGrantedRole(): ?string { return $this->grantedRole; }
    public function getPeriodMonths(): int { return $this->periodMonths; }

    public function getPeriodDays(): int { return $this->periodDays; }
    public function setPeriodDays(int $days): static { $this->periodDays = max(0, $days); return $this; }

    public function markFailed(): static { $this->status = self::STATUS_FAILED; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }

    public function getPaidAt(): ?\DateTimeInterface { return $this->paidAt; }
    public function markPaid(): static
    {
        $this->status = self::STATUS_PAID;
        $this->paidAt = new \DateTimeImmutable();
        return $this;
    }
}
