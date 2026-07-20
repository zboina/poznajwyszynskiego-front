<?php

namespace App\Service\Payment;

/**
 * Zbiór dostępnych bramek. Kontroler pyta o bramkę po nazwie z formularza, a
 * gdy nazwy brak lub jest nieznana — dostaje domyślną.
 *
 * Nazwa przychodzi z formularza, czyli od użytkownika. Dlatego wybór jest
 * zawsze zawężany do bramek skonfigurowanych: podrzucenie `provider=cokolwiek`
 * nie może wywołać błędu ani ominąć płatności, a jedynie cofnąć do domyślnej.
 */
class PaymentGatewayRegistry
{
    /** @var array<string,PaymentGateway> */
    private array $gateways = [];

    /**
     * @param iterable<PaymentGateway> $gateways wstrzykiwane przez autokonfigurację
     */
    public function __construct(iterable $gateways, private string $default = 'stripe')
    {
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway->name()] = $gateway;
        }
    }

    /**
     * Bramki gotowe do użycia — tylko te z kompletem poświadczeń.
     *
     * @return array<string,PaymentGateway>
     */
    public function available(): array
    {
        return array_filter($this->gateways, static fn (PaymentGateway $g): bool => $g->isConfigured());
    }

    public function has(string $name): bool
    {
        return isset($this->available()[$name]);
    }

    /** Czy jakakolwiek płatność jest w ogóle możliwa. */
    public function anyConfigured(): bool
    {
        return $this->available() !== [];
    }

    /**
     * Bramka o podanej nazwie, a gdy jej nie ma — domyślna, a gdy i tej nie ma —
     * pierwsza skonfigurowana.
     *
     * @throws \RuntimeException gdy żadna bramka nie jest skonfigurowana
     */
    public function get(?string $name = null): PaymentGateway
    {
        $available = $this->available();
        if ($available === []) {
            throw new \RuntimeException('Żadna bramka płatnicza nie jest skonfigurowana.');
        }

        if ($name !== null && isset($available[$name])) {
            return $available[$name];
        }
        if (isset($available[$this->default])) {
            return $available[$this->default];
        }

        return reset($available);
    }
}
