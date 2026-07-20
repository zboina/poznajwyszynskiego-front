<?php

namespace App\Tests\Service;

use App\Service\P24Service;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Podpisy Przelewy24 — jedyna część tej integracji, której nie da się sprawdzić
 * „na oko". Zły podpis nie wywala błędu w naszym kodzie: P24 po prostu odrzuca
 * transakcję albo, przy powiadomieniu, my odrzucamy prawdziwą wpłatę. Obie
 * awarie są ciche i kosztowne.
 *
 * Wartości wzorcowe pochodzą wprost z oficjalnej specyfikacji P24
 * (developers.przelewy24.pl, en_documentation_1.0.yaml) — dokumentacja podaje
 * dosłowne ciągi JSON dla merchantId=999999, amount=1000, crc="crc".
 * Test porównuje nasz hash z hashem tych ciągów, więc sprawdza jednocześnie
 * kolejność kluczy, typy i flagi json_encode.
 */
class P24SignatureTest extends TestCase
{
    private function service(): P24Service
    {
        return new P24Service(
            $this->createMock(HttpClientInterface::class),
            merchantId: 999999,
            posId: 999999,
            apiKey: 'nieużywany-w-podpisach',
            crc: 'crc',
            sandbox: true,
        );
    }

    /** Wywołanie metody prywatnej — testujemy podpis, nie sposób jego wywołania. */
    private function callPrivate(string $method, array $args): string
    {
        $ref = new \ReflectionMethod(P24Service::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->service(), $args);
    }

    public function testRegisterSignMatchesSpecExample(): void
    {
        // Ze specyfikacji, sekcja „transaction/register":
        $expected = hash('sha384', '{"sessionId":"sessionId","merchantId":999999,"amount":1000,"currency":"PLN","crc":"crc"}');

        $actual = $this->callPrivate('signRegister', ['sessionId', 1000, 'PLN']);

        self::assertSame($expected, $actual, 'Podpis rejestracji rozjechał się ze wzorcem P24.');
    }

    public function testVerifySignMatchesSpecExample(): void
    {
        // Ze specyfikacji, sekcja „transaction/verify" — orderId zamiast merchantId:
        $expected = hash('sha384', '{"sessionId":"sessionId","orderId":999999,"amount":1000,"currency":"PLN","crc":"crc"}');

        $actual = $this->callPrivate('signVerify', ['sessionId', 999999, 1000, 'PLN']);

        self::assertSame($expected, $actual, 'Podpis weryfikacji rozjechał się ze wzorcem P24.');
    }

    public function testRegisterAndVerifySignsDiffer(): void
    {
        // Strażnik przed pomyłką „jeden helper do wszystkiego": obie struktury
        // mają pięć pól i te same wartości liczbowe, więc wspólna implementacja
        // dawałaby ten sam hash i przeszłaby niezauważona na sandboxie.
        self::assertNotSame(
            $this->callPrivate('signRegister', ['sessionId', 1000, 'PLN']),
            $this->callPrivate('signVerify', ['sessionId', 999999, 1000, 'PLN']),
        );
    }

    public function testNotificationSignIsAccepted(): void
    {
        // Kolejność kluczy wg specyfikacji, sekcja powiadomienia (urlStatus).
        $payload = [
            'merchantId' => 999999,
            'posId' => 999999,
            'sessionId' => 'sessionId',
            'amount' => 1000,
            'originAmount' => 1000,
            'currency' => 'PLN',
            'orderId' => 12345,
            'methodId' => 154,
            'statement' => 'Zapłata za dostęp',
            'crc' => 'crc',
        ];
        $sign = hash('sha384', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $notification = $payload;
        unset($notification['crc']);
        $notification['sign'] = $sign;

        self::assertTrue($this->service()->verifyNotificationSign($notification));
    }

    public function testNotificationWithTamperedAmountIsRejected(): void
    {
        // Właściwy test bezpieczeństwa: ktoś podnosi kwotę, licząc na dostęp VIP
        // za grosze. Podpis liczony jest naszym CRC, więc bez niego nie przejdzie.
        $payload = [
            'merchantId' => 999999, 'posId' => 999999, 'sessionId' => 'sessionId',
            'amount' => 100, 'originAmount' => 100, 'currency' => 'PLN',
            'orderId' => 12345, 'methodId' => 154, 'statement' => 'x', 'crc' => 'crc',
        ];
        $sign = hash('sha384', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $notification = $payload;
        unset($notification['crc']);
        $notification['sign'] = $sign;
        $notification['amount'] = 100000; // podmiana po podpisaniu

        self::assertFalse($this->service()->verifyNotificationSign($notification));
    }

    public function testNotificationWithoutSignIsRejected(): void
    {
        self::assertFalse($this->service()->verifyNotificationSign(['sessionId' => 'x']));
    }

    public function testUnconfiguredGatewayRejectsEverything(): void
    {
        // Gdyby brak konfiguracji nie blokował weryfikacji, podpis liczyłby się
        // pustym CRC — czyli kluczem, który zna każdy. Napastnik nadałby sobie
        // dostęp VIP jednym POST-em, zanim ktokolwiek wpisze prawdziwe klucze.
        $unconfigured = new P24Service(
            $this->createMock(HttpClientInterface::class),
            merchantId: 0, posId: 0, apiKey: '', crc: '', sandbox: true,
        );

        $payload = [
            'merchantId' => 0, 'posId' => 0, 'sessionId' => 'sesja',
            'amount' => 100000, 'originAmount' => 100000, 'currency' => 'PLN',
            'orderId' => 1, 'methodId' => 154, 'statement' => 'x', 'crc' => '',
        ];
        $sign = hash('sha384', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $notification = $payload;
        unset($notification['crc']);
        $notification['sign'] = $sign; // podpis technicznie poprawny dla pustego CRC

        self::assertFalse(
            $unconfigured->verifyNotificationSign($notification),
            'Nieskonfigurowana bramka musi odrzucać powiadomienia, nawet z „poprawnym" podpisem.',
        );
    }

    public function testPolishCharactersInStatementDoNotBreakSign(): void
    {
        // JSON_UNESCAPED_UNICODE jest wymagane — bez niego „ł" trafia do podpisu
        // jako ł i hash się rozjeżdża. Pole statement bywa polskojęzyczne.
        $payload = [
            'merchantId' => 999999, 'posId' => 999999, 'sessionId' => 'sesja',
            'amount' => 1000, 'originAmount' => 1000, 'currency' => 'PLN',
            'orderId' => 1, 'methodId' => 154,
            'statement' => 'Wpłata — Dzieła Zebrane / dostęp VIP', 'crc' => 'crc',
        ];
        $sign = hash('sha384', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $notification = $payload;
        unset($notification['crc']);
        $notification['sign'] = $sign;

        self::assertTrue($this->service()->verifyNotificationSign($notification));
    }
}
