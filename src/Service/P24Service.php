<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Przelewy24 REST API v1 bez SDK — w stylu StripeService: czysty HTTP i podpisy
 * liczone ręcznie.
 *
 * Trzy rzeczy, na których ta integracja może się cicho wyłożyć:
 *
 *  1. PODPISY. Są trzy (rejestracja, powiadomienie, weryfikacja) i każdy ma INNĄ
 *     kolejność kluczy. `posId` występuje w podpisie powiadomienia, ale NIE w
 *     podpisie rejestracji. Kolejność jest znacząca — to hash z konkretnego
 *     ciągu JSON, nie ze zbioru pól. Nigdy nie sortuj tych tablic.
 *
 *  2. TYPY. `merchantId`, `posId`, `amount`, `orderId`, `methodId` idą do JSON-a
 *     jako liczby, reszta jako łańcuchy. `{"amount":1234}` i `{"amount":"1234"}`
 *     dają różne hashe. Stąd jawne rzutowania niżej.
 *
 *  3. FLAGI json_encode. JSON_UNESCAPED_SLASHES i JSON_UNESCAPED_UNICODE są
 *     WYMAGANE — dokumentacja P24 podkreśla to osobno. Bez nich polskie znaki w
 *     polu `statement` i ukośniki w CRC rozjadą podpis.
 *
 * Dwa poświadczenia pełnią różne role i łatwo je pomylić:
 *   – klucz API („klucz do raportów") to HASŁO do Basic Auth, nigdy nie trafia do podpisu,
 *   – CRC to sekret PODPISU, nigdy nie jest wysyłany po sieci.
 */
class P24Service
{
    private const SANDBOX = 'https://sandbox.przelewy24.pl';
    private const PRODUCTION = 'https://secure.przelewy24.pl';

    /** Ile minut klient ma na dokończenie płatności (0 = domyślny czas P24). */
    private const TIME_LIMIT = 20;

    public function __construct(
        private HttpClientInterface $http,
        private int $merchantId,
        private int $posId,
        private string $apiKey,
        private string $crc,
        private bool $sandbox = true,
    ) {}

    public function isConfigured(): bool
    {
        return $this->merchantId > 0 && $this->posId > 0 && $this->apiKey !== '' && $this->crc !== '';
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX : self::PRODUCTION;
    }

    /**
     * Adres, pod który wysyłamy klienta, żeby wybrał bank i zapłacił.
     */
    public function paymentUrl(string $token): string
    {
        return $this->baseUrl() . '/trnRequest/' . $token;
    }

    /**
     * Rejestracja transakcji → token. Sam token nic nie pobiera; dopiero
     * przekierowanie na paymentUrl() pokazuje klientowi wybór banku.
     *
     * @param string $sessionId nasz unikalny klucz transakcji (max 100 znaków)
     * @param int    $amount    w groszach
     *
     * @throws \RuntimeException gdy P24 odrzuci rejestrację
     */
    public function register(
        string $sessionId,
        int $amount,
        string $description,
        string $email,
        string $urlReturn,
        string $urlStatus,
        string $currency = 'PLN',
    ): string {
        $body = [
            'merchantId' => $this->merchantId,
            'posId' => $this->posId,
            'sessionId' => $sessionId,
            'amount' => $amount,
            'currency' => $currency,
            // P24 tnie opis do 1024 znaków — ucinamy sami, żeby nie dostać 400.
            'description' => mb_substr($description, 0, 1024),
            'email' => mb_substr($email, 0, 50),
            'country' => 'PL',
            'language' => 'pl',
            'urlReturn' => $urlReturn,
            'urlStatus' => $urlStatus,
            'timeLimit' => self::TIME_LIMIT,
            'encoding' => 'UTF-8',
            'sign' => $this->signRegister($sessionId, $amount, $currency),
        ];

        $data = $this->request('POST', '/transaction/register', $body);

        $token = $data['data']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Przelewy24 nie zwróciły tokenu transakcji.');
        }

        return $token;
    }

    /**
     * Potwierdzenie transakcji. KROK OBOWIĄZKOWY — dopóki go nie wykonamy, P24
     * traktuje wpłatę jako zaliczkę klienta i NIE przekazuje nam środków.
     * Pominięcie tego wywołania nie daje żadnego widocznego błędu; pieniądze po
     * prostu nigdy nie docierają.
     *
     * @param int $orderId numer transakcji z powiadomienia
     *
     * @throws \RuntimeException gdy P24 nie potwierdzi statusu „success"
     */
    public function verify(string $sessionId, int $orderId, int $amount, string $currency = 'PLN'): void
    {
        $data = $this->request('PUT', '/transaction/verify', [
            'merchantId' => $this->merchantId,
            'posId' => $this->posId,
            'sessionId' => $sessionId,
            'amount' => $amount,
            'currency' => $currency,
            'orderId' => $orderId,
            'sign' => $this->signVerify($sessionId, $orderId, $amount, $currency),
        ]);

        $status = $data['data']['status'] ?? '';
        if ($status !== 'success') {
            throw new \RuntimeException(sprintf('Przelewy24 nie potwierdziły transakcji (status: %s).', (string) $status));
        }
    }

    /**
     * Sprawdzenie poświadczeń (Basic Auth). Przydatne do diagnostyki „czy w
     * ogóle dogadujemy się z P24", bez zakładania transakcji.
     */
    public function testAccess(): bool
    {
        try {
            $data = $this->request('GET', '/testAccess', null);
            return ($data['data'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Weryfikacja podpisu powiadomienia przychodzącego na urlStatus.
     *
     * To jest właściwa granica bezpieczeństwa tej integracji: powiadomienie
     * przychodzi z internetu i bez tego sprawdzenia każdy mógłby nadać sobie
     * dostęp VIP zwykłym POST-em. Lista adresów IP P24 bywa zmieniana i różne
     * źródła podają różne zakresy, więc NIE opieramy na niej autoryzacji.
     *
     * @param array<string,mixed> $n zdekodowane ciało powiadomienia
     */
    public function verifyNotificationSign(array $n): bool
    {
        // Bez kompletu poświadczeń nie ma czym weryfikować. Gdyby przepuścić to
        // dalej, podpis liczyłby się PUSTYM kluczem CRC — a taki potrafi podrobić
        // każdy. Niedokończona konfiguracja nie może otwierać furtki.
        if (!$this->isConfigured()) {
            return false;
        }

        $given = (string) ($n['sign'] ?? '');
        if ($given === '') {
            return false;
        }

        // Kolejność kluczy inna niż przy rejestracji i zawiera posId — patrz nagłówek klasy.
        $expected = $this->sha384([
            'merchantId' => (int) ($n['merchantId'] ?? 0),
            'posId' => (int) ($n['posId'] ?? 0),
            'sessionId' => (string) ($n['sessionId'] ?? ''),
            'amount' => (int) ($n['amount'] ?? 0),
            'originAmount' => (int) ($n['originAmount'] ?? 0),
            'currency' => (string) ($n['currency'] ?? ''),
            'orderId' => (int) ($n['orderId'] ?? 0),
            'methodId' => (int) ($n['methodId'] ?? 0),
            'statement' => (string) ($n['statement'] ?? ''),
            'crc' => $this->crc,
        ]);

        return hash_equals($expected, $given);
    }

    /** Podpis rejestracji — bez posId. */
    private function signRegister(string $sessionId, int $amount, string $currency): string
    {
        return $this->sha384([
            'sessionId' => $sessionId,
            'merchantId' => $this->merchantId,
            'amount' => $amount,
            'currency' => $currency,
            'crc' => $this->crc,
        ]);
    }

    /** Podpis weryfikacji — z orderId, bez merchantId i posId. */
    private function signVerify(string $sessionId, int $orderId, int $amount, string $currency): string
    {
        return $this->sha384([
            'sessionId' => $sessionId,
            'orderId' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'crc' => $this->crc,
        ]);
    }

    /**
     * @param array<string,int|string> $fields kolejność ma znaczenie
     */
    private function sha384(array $fields): string
    {
        return hash('sha384', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $options = [
            // Basic Auth: login = posId, hasło = klucz API. NIE merchantId i NIE CRC.
            // (Dla kont jednopunktowych posId bywa równy merchantId, więc pomyłka
            //  potrafi działać na sandboxie i paść na produkcji z wieloma POS-ami.)
            'auth_basic' => [(string) $this->posId, $this->apiKey],
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $resp = $this->http->request($method, $this->baseUrl() . '/api/v1' . $path, $options);

        $status = $resp->getStatusCode();
        if ($status >= 300) {
            // P24 opisuje błąd w ciele; wyciągamy go, bo samo „HTTP 400" nic nie mówi.
            $detail = '';
            try {
                $err = $resp->toArray(false);
                $detail = is_array($err['error'] ?? null)
                    ? json_encode($err['error'], JSON_UNESCAPED_UNICODE)
                    : (string) ($err['error'] ?? '');
            } catch (\Throwable) {
                $detail = mb_substr($resp->getContent(false), 0, 300);
            }
            throw new \RuntimeException(sprintf('Przelewy24 HTTP %d: %s', $status, $detail));
        }

        return $resp->toArray();
    }
}
