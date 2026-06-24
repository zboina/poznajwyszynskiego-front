<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin Stripe integration without the SDK: creates Checkout Sessions over the
 * REST API and verifies webhook signatures by hand (HMAC-SHA256), matching the
 * project's dependency-light style.
 */
class StripeService
{
    private const API = 'https://api.stripe.com/v1';
    private const WEBHOOK_TOLERANCE = 300; // seconds

    public function __construct(
        private HttpClientInterface $http,
        private string $secretKey,
        private string $webhookSecret,
    ) {}

    public function isConfigured(): bool
    {
        return str_starts_with($this->secretKey, 'sk_');
    }

    /**
     * Create a pay-what-you-want Checkout Session and return its redirect URL.
     *
     * @param array<string,string> $metadata carried back verbatim on the webhook
     */
    public function createCheckoutSession(
        User $user,
        int $amountGrosze,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
    ): string {
        $body = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $user->getEmail(),
            'payment_method_types' => ['card', 'blik', 'p24'],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'pln',
                    'unit_amount' => $amountGrosze,
                    'product_data' => ['name' => 'Darowizna — Dzieła Zebrane kard. Wyszyńskiego'],
                ],
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
        ];

        $resp = $this->http->request('POST', self::API . '/checkout/sessions', [
            'auth_basic' => [$this->secretKey, ''],
            'body' => $body,
        ]);

        $data = $resp->toArray(); // throws on non-2xx
        if (empty($data['url'])) {
            throw new \RuntimeException('Stripe nie zwrócił adresu Checkout.');
        }
        return $data['url'];
    }

    /**
     * Verify a webhook payload against the Stripe-Signature header and return
     * the decoded event. Throws on any mismatch.
     *
     * @return array<string,mixed>
     */
    public function verifyWebhook(string $payload, ?string $sigHeader): array
    {
        if (!$sigHeader) {
            throw new \RuntimeException('Brak nagłówka Stripe-Signature.');
        }
        $t = null;
        $v1 = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') { $t = $v; }
            if ($k === 'v1') { $v1[] = $v; }
        }
        if ($t === null || !$v1) {
            throw new \RuntimeException('Niepoprawny nagłówek podpisu.');
        }
        if (abs(time() - (int) $t) > self::WEBHOOK_TOLERANCE) {
            throw new \RuntimeException('Podpis webhooka przeterminowany.');
        }

        $expected = hash_hmac('sha256', $t . '.' . $payload, $this->webhookSecret);
        $ok = false;
        foreach ($v1 as $sig) {
            if (hash_equals($expected, $sig)) { $ok = true; break; }
        }
        if (!$ok) {
            throw new \RuntimeException('Podpis webhooka nie zgadza się.');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new \RuntimeException('Niepoprawny JSON webhooka.');
        }
        return $event;
    }
}
