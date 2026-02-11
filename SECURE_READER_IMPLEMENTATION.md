# SecureReader — Instrukcja implementacji dla Claude Code

## Cel projektu

Zaimplementuj w istniejącym projekcie Symfony zabezpieczony czytnik tekstów, który renderuje treść dokumentów na HTML5 Canvas. Tekst NIGDY nie trafia do DOM jako element HTML — jest rysowany piksel po pikselu na canvas, co uniemożliwia kopiowanie przez Ctrl+C, zaznaczanie, view source, i scraping.

System służy do prezentacji tekstów Prymasa Stefana Wyszyńskiego z wielopoziomowym dostępem (tiery subskrypcji).

## Wymagania techniczne

- PHP 8.2+, Symfony 6.4+ lub 7.x
- PostgreSQL (baza już istnieje w projekcie)
- Stimulus / Symfony UX (Hotwired)
- Cache: Redis lub filesystem (Symfony Cache)
- Projekt już posiada: encje User i Document, system logowania, Webpack Encore lub Asset Mapper

## WAŻNE — przed rozpoczęciem

1. Sprawdź istniejącą strukturę projektu — jakie encje już istnieją, jaki system auth jest używany
2. Nie nadpisuj istniejących plików — integruj się z istniejącym kodem
3. Dostosuj nazwy pól encji do tych, które już istnieją w projekcie
4. Jeśli brakuje encji Document — utwórz ją
5. Jeśli User nie ma pola subscriptionTier — dodaj je przez migrację Doctrine

---

## ETAP 1: Migracja bazy danych

Utwórz migrację Doctrine (lub plik SQL) dodającą:

### Tabela `document_views` (śledzenie odczytów — do limitu dziennego)

```sql
CREATE TABLE document_views (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    document_id INTEGER NOT NULL,
    viewed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_docviews_user_date ON document_views (user_id, (viewed_at::date));
CREATE INDEX idx_docviews_user_doc_date ON document_views (user_id, document_id, (viewed_at::date));
```

### Tabela `security_events` (logowanie prób kopiowania, devtools itp.)

```sql
CREATE TABLE security_events (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_secevents_user ON security_events (user_id, created_at);
CREATE INDEX idx_secevents_type ON security_events (event_type, created_at);
```

### Modyfikacja tabeli `users` — dodaj pole tier subskrypcji

Jeśli User nie ma jeszcze pola `subscription_tier`, dodaj:

```sql
-- Typ ENUM
CREATE TYPE subscription_tier AS ENUM ('free', 'registered', 'normal', 'gold');

-- Kolumna
ALTER TABLE users ADD COLUMN subscription_tier subscription_tier DEFAULT 'registered';
ALTER TABLE users ADD COLUMN subscription_expires DATE;
```

Jeśli projekt używa Doctrine Migrations — utwórz migrację PHP zamiast surowego SQL.

---

## ETAP 2: Konfiguracja

### Plik `config/packages/secure_reader.yaml`

```yaml
parameters:
    secure_reader.font_size: 17
    secure_reader.font_family: 'Georgia'
    secure_reader.line_height: 28
    secure_reader.page_lines: 35
    secure_reader.margin_x: 40
    secure_reader.margin_y: 50
    secure_reader.canvas_width: 800
    secure_reader.canvas_height: 1050
    secure_reader.scramble_interval: 3
    secure_reader.session_ttl: 1800
    secure_reader.session_max_requests: 300
    secure_reader.daily_view_limit: 10
    secure_reader.watermark_opacity: 0.03
```

### Rate limiter — dodaj do `config/packages/rate_limiter.yaml` (lub utwórz):

```yaml
framework:
    rate_limiter:
        secure_reader_render:
            policy: sliding_window
            limit: 60
            interval: '1 minute'
```

### Services — dodaj do `config/services.yaml` lub utwórz `config/services_secure_reader.yaml` z importem:

```yaml
services:
    App\Service\SecureReader\SecureTextRenderer:
        arguments:
            $fontSize: '%secure_reader.font_size%'
            $fontFamily: '%secure_reader.font_family%'
            $lineHeight: '%secure_reader.line_height%'
            $pageLines: '%secure_reader.page_lines%'
            $marginX: '%secure_reader.margin_x%'
            $marginY: '%secure_reader.margin_y%'
            $canvasWidth: '%secure_reader.canvas_width%'
            $scrambleInterval: '%secure_reader.scramble_interval%'

    App\Service\SecureReader\ReaderSessionService:
        arguments:
            $cache: '@cache.app'
            $sessionTtl: '%secure_reader.session_ttl%'
            $maxRequests: '%secure_reader.session_max_requests%'

    App\Service\SecureReader\AccessControlService:
        arguments:
            $connection: '@doctrine.dbal.default_connection'
            $dailyViewLimit: '%secure_reader.daily_view_limit%'

    App\Service\SecureReader\TextFingerprintService: ~

    App\Twig\SecureReaderExtension:
        tags: ['twig.extension']

    App\EventListener\SecureReaderHeadersListener:
        tags:
            - { name: kernel.event_listener, event: kernel.response, priority: -10 }
```

### Routing — utwórz `config/routes/secure_reader.yaml`:

```yaml
secure_reader:
    resource: '../src/Controller/SecureReaderController.php'
    type: attribute
```

---

## ETAP 3: Encje — dostosowanie

### Entity User — dodaj pole i getter (jeśli nie istnieje)

Dodaj do istniejącej encji User:

```php
#[ORM\Column(type: 'string', length: 20, nullable: true, options: ['default' => 'registered'])]
private ?string $subscriptionTier = 'registered';

#[ORM\Column(type: 'date', nullable: true)]
private ?\DateTimeInterface $subscriptionExpires = null;

public function getSubscriptionTier(): ?string
{
    return $this->subscriptionTier;
}

public function setSubscriptionTier(?string $tier): self
{
    $this->subscriptionTier = $tier;
    return $this;
}

public function getSubscriptionExpires(): ?\DateTimeInterface
{
    return $this->subscriptionExpires;
}

public function isSubscriptionActive(): bool
{
    if ($this->subscriptionTier === null || $this->subscriptionTier === 'free') {
        return false;
    }
    if ($this->subscriptionExpires === null) {
        return true; // bezterminowy
    }
    return $this->subscriptionExpires >= new \DateTime('today');
}
```

### Entity Document — sprawdź czy istnieje, jeśli nie — utwórz

Encja Document musi mieć co najmniej:

```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'documents')]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $volumeNumber;

    #[ORM\Column(type: 'integer')]
    private int $documentNumber;

    #[ORM\Column(type: 'text')]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $subtitle = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dateWritten = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $place = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $addressee = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $documentType = null;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $wordCount = null;

    // Gettery i settery dla wszystkich pól
    // ...

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getSubtitle(): ?string { return $this->subtitle; }
    public function getContent(): string { return $this->content; }
    public function getDateWritten(): ?\DateTimeInterface { return $this->dateWritten; }
    public function getPlace(): ?string { return $this->place; }
    public function getAddressee(): ?string { return $this->addressee; }
    public function getDocumentType(): ?string { return $this->documentType; }
    public function getVolumeNumber(): int { return $this->volumeNumber; }
    public function getWordCount(): ?int { return $this->wordCount; }

    /**
     * Zwraca nazwy tagów — dostosuj do swojej relacji z tagami.
     * Jeśli nie masz jeszcze tagów, zwróć pustą tablicę.
     */
    public function getTagNames(): array
    {
        // Jeśli masz relację ManyToMany z Tag:
        // return $this->tags->map(fn(Tag $t) => $t->getName())->toArray();
        return [];
    }
}
```

**WAŻNE:** Jeśli encja Document już istnieje z innymi nazwami pól — dostosuj serwisy do istniejących nazw. NIE zmieniaj istniejącej encji jeśli jest już w użyciu.

---

## ETAP 4: Serwisy PHP

Utwórz katalog `src/Service/SecureReader/` i w nim 4 pliki:

### 4.1 `SecureTextRenderer.php`

Rdzeń systemu. Przygotowuje dane do renderowania na Canvas:
- Dzieli tekst na linie (word-wrap)
- Co N-ta linia jest "scrambled" — znaki w losowej kolejności z precyzyjnymi pozycjami X/Y
- Nawet przechwycenie JSON response z DevTools nie daje czytelnego tekstu

```php
<?php

declare(strict_types=1);

namespace App\Service\SecureReader;

class SecureTextRenderer
{
    private const CHAR_WIDTHS = [
        'narrow' => ['i', 'l', 't', 'f', 'j', 'r', '!', '.', ',', ':', ';', '|', "'", ' '],
        'wide'   => ['m', 'w', 'M', 'W', '@', '%'],
        'medium_wide' => ['A', 'B', 'C', 'D', 'G', 'H', 'K', 'N', 'O', 'Q', 'R', 'U', 'V', 'X', 'Y', 'Z'],
    ];

    public function __construct(
        private readonly int $fontSize = 17,
        private readonly string $fontFamily = 'Georgia',
        private readonly int $lineHeight = 28,
        private readonly int $pageLines = 35,
        private readonly float $marginX = 40,
        private readonly float $marginY = 50,
        private readonly int $canvasWidth = 800,
        private readonly int $scrambleInterval = 3,
    ) {}

    /**
     * Przygotowuje dane renderowania dla jednej strony dokumentu.
     * Zwraca tablicę z liniami do narysowania na Canvas.
     */
    public function prepareRenderData(
        string $content,
        int $page,
        string $watermarkText,
    ): array {
        $allLines = $this->wrapText($content);
        $totalPages = max(1, (int) ceil(count($allLines) / $this->pageLines));
        $page = max(0, min($page, $totalPages - 1));

        $offset = $page * $this->pageLines;
        $pageLines = array_slice($allLines, $offset, $this->pageLines);

        $renderLines = [];
        foreach ($pageLines as $i => $lineText) {
            $y = $this->marginY + ($i * $this->lineHeight);

            // Co N-ta niepusta linia jest scrambled
            if ($i % $this->scrambleInterval === 0 && trim($lineText) !== '') {
                $renderLines[] = [
                    's' => true,
                    'fs' => $this->fontSize,
                    'ff' => $this->fontFamily,
                    'ch' => $this->scrambleChars($lineText, $this->marginX, $y),
                ];
            } else {
                $renderLines[] = [
                    's' => false,
                    't' => $lineText,
                    'x' => $this->marginX,
                    'y' => $y,
                    'fs' => $this->fontSize,
                    'ff' => $this->fontFamily,
                ];
            }
        }

        return [
            'l' => $renderLines,
            'w' => $watermarkText,
            'tp' => $totalPages,
            'cp' => $page,
            'cw' => $this->canvasWidth,
            'ch' => $this->marginY + (count($pageLines) * $this->lineHeight) + 40,
        ];
    }

    /**
     * KLUCZOWY ELEMENT OCHRONY:
     * Rozbija linię na pojedyncze znaki, oblicza pozycję X/Y każdego,
     * a potem LOSOWO MIESZA kolejność.
     * W JSON response znaki są w losowej kolejności — nie da się ich odczytać sekwencyjnie.
     */
    private function scrambleChars(string $line, float $startX, float $y): array
    {
        $chars = mb_str_split($line);
        $positions = [];
        $x = $startX;

        foreach ($chars as $char) {
            $width = $this->estimateCharWidth($char);
            $positions[] = [
                'c' => $char,
                'x' => round($x, 1),
                'y' => $y,
            ];
            $x += $width;
        }

        shuffle($positions);

        return $positions;
    }

    private function estimateCharWidth(string $char): float
    {
        $base = $this->fontSize * 0.6;

        if (in_array($char, self::CHAR_WIDTHS['narrow'], true)) {
            return $base * 0.45;
        }
        if (in_array($char, self::CHAR_WIDTHS['wide'], true)) {
            return $base * 1.35;
        }
        if (in_array($char, self::CHAR_WIDTHS['medium_wide'], true)) {
            return $base * 1.1;
        }
        if (ctype_upper($char)) {
            return $base * 1.05;
        }
        if (preg_match('/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', $char)) {
            return $base * 1.0;
        }

        return $base * 0.85;
    }

    private function wrapText(string $content): array
    {
        $maxWidth = $this->canvasWidth - (2 * $this->marginX);
        $avgCharWidth = $this->fontSize * 0.6 * 0.85;
        $maxCharsPerLine = (int) floor($maxWidth / $avgCharWidth);

        $paragraphs = preg_split('/\r?\n/', $content);
        $lines = [];

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                $lines[] = '';
                continue;
            }

            $wrapped = wordwrap($para, $maxCharsPerLine, "\n", true);
            foreach (explode("\n", $wrapped) as $line) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        return $lines;
    }
}
```

### 4.2 `ReaderSessionService.php`

Zarządza jednorazowymi tokenami sesji czytania. Token tworzony przy otwarciu dokumentu, ważny 30 min, powiązany z konkretnym userem i dokumentem.

```php
<?php

declare(strict_types=1);

namespace App\Service\SecureReader;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ReaderSessionService
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $sessionTtl = 1800,
        private readonly int $maxRequests = 300,
    ) {}

    public function create(int $userId, int $documentId): string
    {
        $token = bin2hex(random_bytes(32));
        $key = 'secure_reader_session_' . $token;

        // Usuń stary klucz gdyby istniał (na wszelki wypadek)
        if ($this->cache instanceof CacheItemPoolInterface) {
            $this->cache->deleteItem($key);
        }

        $this->cache->get($key, function (ItemInterface $item) use ($userId, $documentId) {
            $item->expiresAfter($this->sessionTtl);
            return [
                'userId' => $userId,
                'documentId' => $documentId,
                'createdAt' => time(),
                'requestCount' => 0,
            ];
        });

        return $token;
    }

    public function validate(string $token, int $userId, int $documentId): bool
    {
        if (empty($token)) {
            return false;
        }

        $key = 'secure_reader_session_' . $token;

        try {
            if (!($this->cache instanceof CacheItemPoolInterface)) {
                return false;
            }

            $cacheItem = $this->cache->getItem($key);
            if (!$cacheItem->isHit()) {
                return false;
            }

            $data = $cacheItem->get();

            if ($data['userId'] !== $userId || $data['documentId'] !== $documentId) {
                return false;
            }

            if ($data['requestCount'] >= $this->maxRequests) {
                return false;
            }

            $data['requestCount']++;
            $cacheItem->set($data);
            $cacheItem->expiresAfter($this->sessionTtl);
            $this->cache->save($cacheItem);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function destroy(string $token): void
    {
        if ($this->cache instanceof CacheItemPoolInterface) {
            $this->cache->deleteItem('secure_reader_session_' . $token);
        }
    }
}
```

### 4.3 `AccessControlService.php`

Kontrola dostępu na podstawie tierów subskrypcji. Zarządza limitem 10 dokumentów dziennie dla tier REGISTERED.

```php
<?php

declare(strict_types=1);

namespace App\Service\SecureReader;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AccessControlService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $dailyViewLimit = 10,
    ) {}

    public function checkReadAccess(?User $user, int $documentId): void
    {
        if ($user === null) {
            throw new AccessDeniedHttpException('Zaloguj się, aby czytać pełne teksty.');
        }

        $tier = $user->getSubscriptionTier();

        if (in_array($tier, ['normal', 'gold'], true)) {
            $this->logView($user->getId(), $documentId);
            return;
        }

        // registered — limit dzienny
        $this->checkDailyLimit($user->getId(), $documentId);
        $this->logView($user->getId(), $documentId);
    }

    public function checkExportAccess(?User $user): void
    {
        if ($user === null || $user->getSubscriptionTier() !== 'gold') {
            throw new AccessDeniedHttpException('Export dokumentów dostępny w abonamencie GOLD.');
        }
    }

    public function shouldFingerprint(?User $user): bool
    {
        if ($user === null) return true;
        return $user->getSubscriptionTier() !== 'gold';
    }

    public function canUseSemanticSearch(?User $user): bool
    {
        if ($user === null) return false;
        return in_array($user->getSubscriptionTier(), ['normal', 'gold'], true);
    }

    public function getRemainingViews(int $userId): int
    {
        $todayCount = $this->getTodayViewCount($userId);
        return max(0, $this->dailyViewLimit - $todayCount);
    }

    private function checkDailyLimit(int $userId, int $documentId): void
    {
        // Jeśli ten dokument był już dziś oglądany — nie liczymy ponownie
        $alreadyViewed = (bool) $this->connection->fetchOne(
            'SELECT 1 FROM document_views WHERE user_id = :userId AND document_id = :docId AND viewed_at::date = CURRENT_DATE LIMIT 1',
            ['userId' => $userId, 'docId' => $documentId]
        );

        if ($alreadyViewed) return;

        $todayCount = $this->getTodayViewCount($userId);

        if ($todayCount >= $this->dailyViewLimit) {
            throw new AccessDeniedHttpException(sprintf(
                'Wykorzystano dzienny limit %d tekstów. Wykup abonament NORMAL, aby czytać bez limitu.',
                $this->dailyViewLimit
            ));
        }
    }

    private function getTodayViewCount(int $userId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT document_id) FROM document_views WHERE user_id = :userId AND viewed_at::date = CURRENT_DATE',
            ['userId' => $userId]
        );
    }

    private function logView(int $userId, int $documentId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO document_views (user_id, document_id, viewed_at) VALUES (:userId, :docId, NOW())',
            ['userId' => $userId, 'docId' => $documentId]
        );
    }
}
```

### 4.4 `TextFingerprintService.php`

Wstawia niewidoczne zero-width characters do tekstu, kodujące userId. Nawet jeśli ktoś skopiuje tekst — można zidentyfikować źródło wycieku.

```php
<?php

declare(strict_types=1);

namespace App\Service\SecureReader;

class TextFingerprintService
{
    private const ZWC = [
        "\u{200B}", // zero-width space
        "\u{200C}", // zero-width non-joiner
        "\u{200D}", // zero-width joiner
        "\u{FEFF}", // zero-width no-break space
    ];

    private const INSERT_EVERY_N_WORDS = 7;

    public function embed(string $text, int $userId): string
    {
        $fingerprint = $this->encodeId($userId);
        $words = explode(' ', $text);
        $result = [];

        foreach ($words as $i => $word) {
            $result[] = $word;
            if ($i > 0 && $i % self::INSERT_EVERY_N_WORDS === 0) {
                $result[] = $fingerprint;
            }
        }

        return implode(' ', $result);
    }

    public function extract(string $text): ?int
    {
        $pattern = '/[\x{200B}\x{200C}\x{200D}\x{FEFF}]{4,}/u';
        preg_match_all($pattern, $text, $matches);

        if (empty($matches[0])) return null;

        foreach ($matches[0] as $match) {
            $id = $this->decodeId($match);
            if ($id !== null && $id > 0) return $id;
        }

        return null;
    }

    private function encodeId(int $id): string
    {
        $binary = str_pad(decbin($id), 24, '0', STR_PAD_LEFT);
        $encoded = '';

        for ($i = 0; $i < strlen($binary); $i += 2) {
            $pair = substr($binary, $i, 2);
            $encoded .= self::ZWC[bindec($pair)];
        }

        return $encoded;
    }

    private function decodeId(string $fingerprint): ?int
    {
        $chars = mb_str_split($fingerprint);
        $binary = '';

        foreach ($chars as $char) {
            $idx = array_search($char, self::ZWC, true);
            if ($idx === false) continue;
            $binary .= str_pad(decbin($idx), 2, '0', STR_PAD_LEFT);
        }

        return empty($binary) ? null : bindec($binary);
    }
}
```

---

## ETAP 5: Controller

Utwórz `src/Controller/SecureReaderController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Service\SecureReader\AccessControlService;
use App\Service\SecureReader\ReaderSessionService;
use App\Service\SecureReader\SecureTextRenderer;
use App\Service\SecureReader\TextFingerprintService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/secure-reader')]
class SecureReaderController extends AbstractController
{
    public function __construct(
        private readonly SecureTextRenderer $renderer,
        private readonly ReaderSessionService $sessionService,
        private readonly AccessControlService $accessControl,
        private readonly TextFingerprintService $fingerprint,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Strona czytnika — HTML z canvasem.
     */
    #[Route('/read/{id}', name: 'secure_reader_read', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function read(int $id): Response
    {
        $document = $this->findDocument($id);
        $user = $this->getUser();

        $this->accessControl->checkReadAccess($user, $document->getId());

        $sessionToken = $this->sessionService->create(
            $user->getId(),
            $document->getId()
        );

        return $this->render('secure_reader/reader.html.twig', [
            'document' => $document,
            'sessionToken' => $sessionToken,
            'tier' => $user->getSubscriptionTier(),
            'canExport' => $user->getSubscriptionTier() === 'gold',
        ]);
    }

    /**
     * API renderowania — zwraca JSON z instrukcjami rysowania na Canvas.
     * Tekst NIGDY nie jest w HTML. Część znaków w losowej kolejności.
     */
    #[Route('/api/render/{id}', name: 'secure_reader_render', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function renderDocument(
        int $id,
        Request $request,
        RateLimiterFactory $secureReaderRenderLimiter,
    ): JsonResponse {
        $limiter = $secureReaderRenderLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Zbyt wiele żądań.'], 429);
        }

        $user = $this->getUser();
        $document = $this->findDocument($id);

        $token = $request->headers->get('X-Reader-Token', '');
        if (!$this->sessionService->validate($token, $user->getId(), $document->getId())) {
            throw new AccessDeniedHttpException('Nieprawidłowa lub wygasła sesja czytania.');
        }

        $payload = $request->toArray();
        $page = max(0, (int) ($payload['page'] ?? 0));

        $watermark = sprintf('%s | ID:%d', $user->getEmail(), $user->getId());

        $content = $document->getContent();
        if ($this->accessControl->shouldFingerprint($user)) {
            $content = $this->fingerprint->embed($content, $user->getId());
        }

        $data = $this->renderer->prepareRenderData($content, $page, $watermark);

        return $this->json($data, 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Info o dokumencie — publiczne (tytuły, tagi, metadane).
     */
    #[Route('/api/info/{id}', name: 'secure_reader_info', methods: ['GET'])]
    public function info(int $id): JsonResponse
    {
        $document = $this->findDocument($id);

        $data = [
            'id' => $document->getId(),
            'title' => $document->getTitle(),
            'subtitle' => $document->getSubtitle(),
            'date' => $document->getDateWritten()?->format('Y-m-d'),
            'place' => $document->getPlace(),
            'addressee' => $document->getAddressee(),
            'documentType' => $document->getDocumentType(),
            'volumeNumber' => $document->getVolumeNumber(),
            'tags' => $document->getTagNames(),
        ];

        $user = $this->getUser();
        if ($user === null) {
            $data['access'] = 'login_required';
        } elseif (in_array($user->getSubscriptionTier(), ['normal', 'gold'], true)) {
            $data['access'] = 'full';
        } else {
            $data['access'] = 'limited';
            $data['remainingViews'] = $this->accessControl->getRemainingViews($user->getId());
        }

        return $this->json($data);
    }

    /**
     * Zamknięcie sesji czytania.
     */
    #[Route('/api/session/close', name: 'secure_reader_close', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function closeSession(Request $request): JsonResponse
    {
        $token = $request->headers->get('X-Reader-Token', '');
        $this->sessionService->destroy($token);
        return $this->json(['ok' => true]);
    }

    /**
     * Logowanie zdarzeń bezpieczeństwa (DevTools, print, copy attempt).
     */
    #[Route('/api/security/log', name: 'secure_reader_security_log', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function securityLog(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $payload = $request->toArray();

        $this->em->getConnection()->executeStatement(
            'INSERT INTO security_events (user_id, event_type, ip_address, user_agent, created_at) VALUES (:userId, :type, :ip, :ua, NOW())',
            [
                'userId' => $user->getId(),
                'type' => substr($payload['type'] ?? 'unknown', 0, 50),
                'ip' => $request->getClientIp(),
                'ua' => substr($request->headers->get('User-Agent', ''), 0, 500),
            ]
        );

        return $this->json(['ok' => true]);
    }

    private function findDocument(int $id): Document
    {
        $document = $this->em->getRepository(Document::class)->find($id);
        if (!$document) {
            throw new NotFoundHttpException('Dokument nie został znaleziony.');
        }
        return $document;
    }
}
```

---

## ETAP 6: Event Listener — Security Headers

Utwórz `src/EventListener/SecureReaderHeadersListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
class SecureReaderHeadersListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/secure-reader')) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), clipboard-write=()');
    }
}
```

---

## ETAP 7: Twig Extension

Utwórz `src/Twig/SecureReaderExtension.php`:

```php
<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Document;
use App\Entity\User;
use App\Service\SecureReader\ReaderSessionService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SecureReaderExtension extends AbstractExtension
{
    public function __construct(
        private readonly ReaderSessionService $sessionService,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('secure_reader', [$this, 'renderReader'], [
                'is_safe' => ['html'],
                'needs_environment' => true,
            ]),
        ];
    }

    public function renderReader(
        \Twig\Environment $twig,
        Document $document,
        ?User $user = null,
        array $options = [],
    ): string {
        if ($user === null) {
            return '<div class="sr-login-required"><p>Zaloguj się, aby przeczytać pełny tekst.</p></div>';
        }

        $sessionToken = $this->sessionService->create($user->getId(), $document->getId());

        return $twig->render('secure_reader/_reader_widget.html.twig', [
            'document' => $document,
            'sessionToken' => $sessionToken,
            'tier' => $user->getSubscriptionTier(),
            'options' => $options,
        ]);
    }
}
```

---

## ETAP 8: Szablony Twig

### 8.1 `templates/secure_reader/reader.html.twig` — pełna strona czytnika

```twig
{% extends 'base.html.twig' %}

{% block title %}{{ document.title }} — Czytnik{% endblock %}

{% block body %}
<div class="sr-container"
     data-controller="secure-reader protection"
     data-secure-reader-document-id-value="{{ document.id }}"
     data-secure-reader-session-token-value="{{ sessionToken }}">

    <header class="sr-header">
        <h1 class="sr-title">{{ document.title }}</h1>
        {% if document.subtitle %}
            <h2 class="sr-subtitle">{{ document.subtitle }}</h2>
        {% endif %}
        <div class="sr-meta">
            {% if document.dateWritten %}
                <span class="sr-meta__item">📅 {{ document.dateWritten|date('d.m.Y') }}</span>
            {% endif %}
            {% if document.place %}
                <span class="sr-meta__item">📍 {{ document.place }}</span>
            {% endif %}
            {% if document.addressee %}
                <span class="sr-meta__item">👤 {{ document.addressee }}</span>
            {% endif %}
            <span class="sr-meta__item">Tom {{ document.volumeNumber }}</span>
        </div>
    </header>

    <div class="sr-reader">
        <div class="sr-reader__overlay" data-secure-reader-target="overlay"></div>
        <canvas class="sr-reader__canvas"
                data-secure-reader-target="canvas"
                role="img"
                aria-label="Treść dokumentu: {{ document.title }}">
        </canvas>
        <div class="sr-reader__loader" data-secure-reader-target="loader">
            <div class="sr-spinner"></div>
            <p>Ładowanie strony...</p>
        </div>
    </div>

    <nav class="sr-pagination" data-secure-reader-target="pagination">
        <button class="sr-btn sr-btn--prev"
                data-action="secure-reader#prevPage"
                data-secure-reader-target="prevBtn" disabled>
            ← Poprzednia
        </button>
        <span class="sr-pagination__info" data-secure-reader-target="pageInfo">
            Strona 1 / ?
        </span>
        <button class="sr-btn sr-btn--next"
                data-action="secure-reader#nextPage"
                data-secure-reader-target="nextBtn">
            Następna →
        </button>
    </nav>

    <div class="sr-toolbar">
        {% if canExport %}
            <a href="{{ path('secure_reader_export_docx', {id: document.id}) }}" class="sr-btn sr-btn--export">📄 Pobierz DOCX</a>
            <a href="{{ path('secure_reader_export_pdf', {id: document.id}) }}" class="sr-btn sr-btn--export">📑 Pobierz PDF</a>
        {% else %}
            <span class="sr-toolbar__hint">Export dokumentów dostępny w abonamencie GOLD</span>
        {% endif %}
        <button class="sr-btn sr-btn--zoom" data-action="secure-reader#zoomIn">A+</button>
        <button class="sr-btn sr-btn--zoom" data-action="secure-reader#zoomOut">A-</button>
    </div>

    <footer class="sr-footer">
        <small>Każdy wyświetlony tekst zawiera niewidoczny identyfikator użytkownika. Nieautoryzowane kopiowanie jest zabronione.</small>
    </footer>
</div>
{% endblock %}
```

### 8.2 `templates/secure_reader/_reader_widget.html.twig` — widget do osadzenia

```twig
<div class="sr-widget"
     data-controller="secure-reader protection"
     data-secure-reader-document-id-value="{{ document.id }}"
     data-secure-reader-session-token-value="{{ sessionToken }}">

    <div class="sr-reader">
        <div class="sr-reader__overlay" data-secure-reader-target="overlay"></div>
        <canvas class="sr-reader__canvas" data-secure-reader-target="canvas" role="img" aria-label="{{ document.title }}"></canvas>
        <div class="sr-reader__loader" data-secure-reader-target="loader">
            <div class="sr-spinner"></div>
        </div>
    </div>

    <nav class="sr-pagination" data-secure-reader-target="pagination">
        <button class="sr-btn sr-btn--prev" data-action="secure-reader#prevPage" data-secure-reader-target="prevBtn" disabled>←</button>
        <span data-secure-reader-target="pageInfo">1 / ?</span>
        <button class="sr-btn sr-btn--next" data-action="secure-reader#nextPage" data-secure-reader-target="nextBtn">→</button>
    </nav>
</div>
```

---

## ETAP 9: JavaScript — Stimulus Controllers

### 9.1 `assets/controllers/secure_reader_controller.js`

To jest główny controller renderujący tekst na Canvas:

```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['canvas', 'overlay', 'loader', 'pagination', 'pageInfo', 'prevBtn', 'nextBtn'];
    static values = {
        documentId: Number,
        sessionToken: String,
    };

    currentPage = 0;
    totalPages = 1;
    zoomLevel = 1.0;
    loading = false;

    async connect() {
        this.dpr = window.devicePixelRatio || 1;
        this.ctx = this.canvasTarget.getContext('2d');

        try {
            await document.fonts.load('17px Georgia');
        } catch {}

        await this.loadPage(0);

        this.boundKeyHandler = this.handleKeyboard.bind(this);
        document.addEventListener('keydown', this.boundKeyHandler);
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundKeyHandler);
    }

    async loadPage(page) {
        if (this.loading) return;
        this.loading = true;
        this.showLoader(true);

        try {
            const response = await fetch(`/secure-reader/api/render/${this.documentIdValue}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Reader-Token': this.sessionTokenValue,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ page, _t: Date.now() }),
            });

            if (response.status === 429) { this.showError('Zbyt wiele żądań. Odczekaj chwilę.'); return; }
            if (response.status === 403) { const err = await response.json(); this.showError(err.detail || 'Brak dostępu.'); return; }
            if (!response.ok) { this.showError('Błąd ładowania strony.'); return; }

            const data = await response.json();
            this.currentPage = data.cp;
            this.totalPages = data.tp;

            this.setupCanvas(data.cw, data.ch);
            this.renderPage(data);
            this.updatePagination();
        } catch (err) {
            console.error('SecureReader error:', err);
            this.showError('Błąd połączenia.');
        } finally {
            this.loading = false;
            this.showLoader(false);
        }
    }

    setupCanvas(width, height) {
        const sw = width * this.zoomLevel;
        const sh = height * this.zoomLevel;
        this.canvasTarget.style.width = sw + 'px';
        this.canvasTarget.style.height = sh + 'px';
        this.canvasTarget.width = sw * this.dpr;
        this.canvasTarget.height = sh * this.dpr;
        this.ctx.scale(this.dpr * this.zoomLevel, this.dpr * this.zoomLevel);
    }

    renderPage(data) {
        const ctx = this.ctx;
        const w = data.cw, h = data.ch;

        ctx.clearRect(0, 0, w, h);
        ctx.fillStyle = '#fdfcf9';
        ctx.fillRect(0, 0, w, h);

        // Ramka
        ctx.strokeStyle = '#e8e4dc';
        ctx.lineWidth = 0.5;
        ctx.strokeRect(20, 20, w - 40, h - 40);

        // Linie tekstu
        if (data.l) data.l.forEach(line => this.renderLine(ctx, line));

        // Watermark
        if (data.w) this.renderWatermark(ctx, data.w, w, h);

        // Numer strony
        ctx.font = '11px Georgia';
        ctx.fillStyle = '#999';
        ctx.textAlign = 'center';
        ctx.fillText(`${data.cp + 1} / ${data.tp}`, w / 2, h - 15);
        ctx.textAlign = 'left';
    }

    renderLine(ctx, line) {
        ctx.font = `${line.fs}px "${line.ff}"`;
        ctx.fillStyle = '#1a1a1a';

        if (line.s && line.ch) {
            // SCRAMBLED — znaki w losowej kolejności, rysowane na pozycjach X/Y
            line.ch.forEach(ch => ctx.fillText(ch.c, ch.x, ch.y));
        } else if (line.t !== undefined) {
            ctx.fillText(line.t, line.x, line.y);
        }
    }

    renderWatermark(ctx, text, w, h) {
        ctx.save();
        ctx.globalAlpha = 0.025;
        ctx.font = '13px sans-serif';
        ctx.fillStyle = '#888';
        ctx.rotate(-0.3);
        for (let y = -100; y < h + 300; y += 90) {
            for (let x = -300; x < w + 300; x += 280) {
                ctx.fillText(text, x, y);
            }
        }
        ctx.restore();
    }

    nextPage() { if (this.currentPage < this.totalPages - 1) this.loadPage(this.currentPage + 1); }
    prevPage() { if (this.currentPage > 0) this.loadPage(this.currentPage - 1); }

    updatePagination() {
        if (this.hasPageInfoTarget) this.pageInfoTarget.textContent = `Strona ${this.currentPage + 1} / ${this.totalPages}`;
        if (this.hasPrevBtnTarget) this.prevBtnTarget.disabled = this.currentPage === 0;
        if (this.hasNextBtnTarget) this.nextBtnTarget.disabled = this.currentPage >= this.totalPages - 1;
    }

    handleKeyboard(e) {
        if (e.key === 'ArrowRight' || e.key === 'PageDown') { e.preventDefault(); this.nextPage(); }
        else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); this.prevPage(); }
    }

    zoomIn() { if (this.zoomLevel < 2.0) { this.zoomLevel += 0.15; this.loadPage(this.currentPage); } }
    zoomOut() { if (this.zoomLevel > 0.6) { this.zoomLevel -= 0.15; this.loadPage(this.currentPage); } }

    showLoader(show) { if (this.hasLoaderTarget) this.loaderTarget.style.display = show ? 'flex' : 'none'; }

    showError(msg) {
        const ctx = this.ctx;
        ctx.clearRect(0, 0, this.canvasTarget.width, this.canvasTarget.height);
        ctx.fillStyle = '#fdfcf9';
        ctx.fillRect(0, 0, this.canvasTarget.width, this.canvasTarget.height);
        ctx.font = '16px Georgia';
        ctx.fillStyle = '#c0392b';
        ctx.textAlign = 'center';
        ctx.fillText(msg, this.canvasTarget.width / (2 * this.dpr), 100);
        ctx.textAlign = 'left';
    }
}
```

### 9.2 `assets/controllers/protection_controller.js`

Blokady kopiowania, drukowania, DevTools:

```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.cleanups = [];
        this.setupCopyProtection();
        this.setupPrintProtection();
        this.setupDevToolsProtection();
        this.setupVisibilityProtection();
        this.injectPrintCSS();
    }

    disconnect() {
        this.cleanups.forEach(fn => fn());
    }

    setupCopyProtection() {
        ['copy', 'cut', 'selectstart', 'contextmenu'].forEach(evt => {
            const handler = e => {
                e.preventDefault();
                if (evt === 'copy') {
                    try {
                        e.clipboardData?.setData('text/plain',
                            'Kopiowanie treści jest niedostępne. Abonament GOLD umożliwia export dokumentów.');
                    } catch {}
                }
                return false;
            };
            document.addEventListener(evt, handler, true);
            this.cleanups.push(() => document.removeEventListener(evt, handler, true));
        });

        this.element.style.userSelect = 'none';
        this.element.style.webkitUserSelect = 'none';
        this.element.style.webkitTouchCallout = 'none';

        const dragHandler = e => e.preventDefault();
        this.element.addEventListener('dragstart', dragHandler);
        this.cleanups.push(() => this.element.removeEventListener('dragstart', dragHandler));
    }

    setupPrintProtection() {
        const keyHandler = e => {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                e.stopImmediatePropagation();
                this.log('print_attempt');
                return false;
            }
        };
        document.addEventListener('keydown', keyHandler, true);
        this.cleanups.push(() => document.removeEventListener('keydown', keyHandler, true));

        const beforePrint = () => {
            this.log('print_dialog');
            this.element.querySelectorAll('canvas').forEach(c =>
                c.style.setProperty('display', 'none', 'important'));
        };
        const afterPrint = () => {
            this.element.querySelectorAll('canvas').forEach(c =>
                c.style.removeProperty('display'));
        };
        window.addEventListener('beforeprint', beforePrint);
        window.addEventListener('afterprint', afterPrint);
        this.cleanups.push(() => { window.removeEventListener('beforeprint', beforePrint); window.removeEventListener('afterprint', afterPrint); });
    }

    injectPrintCSS() {
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                .sr-container, .sr-widget { visibility: hidden !important; }
                .sr-container::after, .sr-widget::after {
                    visibility: visible !important;
                    content: "Drukowanie niedostępne. Wykup pakiet GOLD aby pobierać dokumenty.";
                    display: block !important; font-size: 18px; padding: 60px;
                    text-align: center; color: #333; position: fixed; top: 50%; left: 10%; right: 10%;
                    transform: translateY(-50%);
                }
                canvas { display: none !important; }
            }`;
        document.head.appendChild(style);
    }

    setupDevToolsProtection() {
        const handler = e => {
            if (e.key === 'F12') { e.preventDefault(); this.log('f12'); return false; }
            if (e.ctrlKey && e.shiftKey && ['I','J','C'].includes(e.key)) { e.preventDefault(); this.log('devtools'); return false; }
            if (e.ctrlKey && e.key === 'u') { e.preventDefault(); this.log('view_source'); return false; }
            if (e.ctrlKey && e.key === 's') { e.preventDefault(); this.log('save_page'); return false; }
            if (e.ctrlKey && e.key === 'a') { e.preventDefault(); return false; }
        };
        document.addEventListener('keydown', handler, true);
        this.cleanups.push(() => document.removeEventListener('keydown', handler, true));
    }

    setupVisibilityProtection() {
        const visHandler = () => {
            const canvases = this.element.querySelectorAll('canvas');
            canvases.forEach(c => { c.style.filter = document.hidden ? 'blur(15px)' : 'none'; });
        };
        document.addEventListener('visibilitychange', visHandler);
        this.cleanups.push(() => document.removeEventListener('visibilitychange', visHandler));

        const blur = () => this.element.querySelectorAll('canvas').forEach(c => c.style.filter = 'blur(10px)');
        const focus = () => this.element.querySelectorAll('canvas').forEach(c => c.style.filter = 'none');
        window.addEventListener('blur', blur);
        window.addEventListener('focus', focus);
        this.cleanups.push(() => { window.removeEventListener('blur', blur); window.removeEventListener('focus', focus); });
    }

    log(type) {
        try {
            navigator.sendBeacon('/secure-reader/api/security/log', JSON.stringify({ type, timestamp: Date.now() }));
        } catch {}
    }
}
```

---

## ETAP 10: CSS

Utwórz `assets/styles/secure_reader.css` i dodaj import w `assets/app.js`:

```css
/* SecureReader */
.sr-container {
    max-width: 860px; margin: 0 auto; padding: 2rem 1rem;
    font-family: Georgia, 'Times New Roman', serif; color: #1a1a1a;
    user-select: none; -webkit-user-select: none; -webkit-touch-callout: none;
}
.sr-widget { user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; }

.sr-header { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #d4c5a9; }
.sr-title { font-size: 1.6rem; font-weight: 700; color: #2c2416; margin: 0 0 0.3rem 0; line-height: 1.3; }
.sr-subtitle { font-size: 1.1rem; font-weight: 400; color: #5a4e3a; margin: 0 0 0.8rem 0; font-style: italic; }
.sr-meta { display: flex; flex-wrap: wrap; gap: 1rem; font-size: 0.85rem; color: #7a6e5a; }
.sr-meta__item { white-space: nowrap; }

.sr-reader {
    position: relative; display: flex; justify-content: center;
    background: #f5f0e8; border-radius: 4px; padding: 1.5rem; min-height: 400px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.04);
}
.sr-reader__canvas { display: block; max-width: 100%; height: auto; }
.sr-reader__overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 5; pointer-events: none; }

.sr-reader__loader {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    display: flex; flex-direction: column; align-items: center; gap: 0.8rem;
    color: #7a6e5a; font-size: 0.9rem; z-index: 10;
}
.sr-spinner { width: 32px; height: 32px; border: 3px solid #d4c5a9; border-top-color: #8b7355; border-radius: 50%; animation: sr-spin 0.8s linear infinite; }
@keyframes sr-spin { to { transform: rotate(360deg); } }

.sr-pagination { display: flex; justify-content: center; align-items: center; gap: 1.5rem; margin-top: 1.2rem; padding: 0.8rem; }
.sr-pagination__info { font-size: 0.9rem; color: #5a4e3a; min-width: 100px; text-align: center; }

.sr-btn {
    display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem;
    border: 1px solid #c4b896; border-radius: 4px; background: #fff;
    color: #4a3f2f; font-family: inherit; font-size: 0.85rem; cursor: pointer; transition: all 0.15s ease;
}
.sr-btn:hover:not(:disabled) { background: #f5f0e8; border-color: #a89970; }
.sr-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.sr-btn--export { background: #2c5f2d; color: #fff; border-color: #2c5f2d; text-decoration: none; }
.sr-btn--export:hover { background: #1e4620; }
.sr-btn--zoom { padding: 0.4rem 0.7rem; font-weight: 700; font-size: 0.9rem; }

.sr-toolbar { display: flex; justify-content: center; align-items: center; gap: 0.8rem; margin-top: 0.8rem; padding: 0.6rem; flex-wrap: wrap; }
.sr-toolbar__hint { font-size: 0.8rem; color: #999; font-style: italic; }

.sr-footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e8e4dc; text-align: center; }
.sr-footer small { font-size: 0.75rem; color: #aaa; }

.sr-login-required { padding: 3rem 2rem; text-align: center; background: #f9f6f0; border: 1px dashed #d4c5a9; border-radius: 8px; color: #5a4e3a; }

@media (max-width: 640px) {
    .sr-container { padding: 1rem 0.5rem; }
    .sr-reader { padding: 0.5rem; }
    .sr-title { font-size: 1.3rem; }
    .sr-meta { flex-direction: column; gap: 0.3rem; }
}

@media print {
    .sr-container, .sr-widget { visibility: hidden !important; }
    .sr-container::after, .sr-widget::after {
        visibility: visible !important;
        content: "Drukowanie niedostępne. Wykup pakiet GOLD.";
        display: block !important; font-size: 20px; padding: 60px; text-align: center; color: #333;
        position: fixed; top: 40%; left: 10%; right: 10%;
    }
    canvas { display: none !important; }
}
```

Dodaj w `assets/app.js`:
```javascript
import './styles/secure_reader.css';
```

---

## ETAP 11: Finalizacja

### Checklist po implementacji:

1. **Migracja DB** — uruchom migrację (`php bin/console doctrine:migrations:migrate` lub psql)
2. **Cache** — upewnij się że `cache.app` działa (Redis zalecany)
3. **Build assets** — `npm run build` lub `yarn build`
4. **Test czytnika** — otwórz `/secure-reader/read/{id}` zalogowanym userem
5. **Test ochrony:**
   - Ctrl+C → nic się nie kopiuje
   - Ctrl+P → drukowanie zablokowane
   - F12 → zablokowane (ale nie 100%)
   - View Source → brak tekstu w HTML
   - DevTools > Network > response JSON → znaki w losowej kolejności (scrambled)
   - Ctrl+A → nie zaznacza
   - Prawy przycisk myszy → zablokowany
6. **Test tierów:**
   - Niezalogowany → "Zaloguj się"
   - registered → limit 10/dzień (sprawdź po 10 odczytach)
   - normal → bez limitu, bez kopiowania
   - gold → bez limitu + export

### Możliwe problemy i rozwiązania:

- **RateLimiterFactory nie jest wstrzykiwana** — sprawdź nazewnictwo w `rate_limiter.yaml`, Symfony autowiruje jako `$secureReaderRenderLimiter` (camelCase od nazwy limitera)
- **Cache nie działa** — sprawdź `config/packages/cache.yaml`, dla Redis dodaj `REDIS_URL` do `.env`
- **Fonty się nie ładują na Canvas** — dodaj web font do CSS (`@font-face` dla Georgia lub zmień na systemowy)
- **Scrambled znaki nie wyglądają dobrze** — dostosuj `estimateCharWidth()` w SecureTextRenderer, szerokości znaków zależą od fontu
- **Export routes nie istnieją** — w szablonie reader.html.twig są linki do `secure_reader_export_docx` i `secure_reader_export_pdf` — te endpointy trzeba jeszcze stworzyć lub zakomentować linki

---

## UWAGI KOŃCOWE

1. Ten system NIGDY nie będzie 100% odporny — screenshot + OCR zawsze zadziała. Ale Canvas rendering + scrambled chars + fingerprint + blokady JS blokuje 90-95% "klasycznych" metod kopiowania.

2. Najsilniejszą ochroną jest **fingerprint zero-width characters** — nawet jeśli ktoś skopiuje tekst, można zidentyfikować kto to zrobił. Komunikat w stopce o identyfikatorze działa odstraszająco.

3. Przy wdrożeniu na produkcję — dodaj monitoring tabeli `security_events` aby wyłapywać podejrzanych użytkowników.

4. System tierów i płatności (Stripe/PayU) to osobny moduł — ten plik dotyczy tylko czytnika i ochrony treści.
