-- Przelewy24 obok Stripe: płatność zapisywana ZANIM klient wyjdzie na bramkę.
-- Uruchom ręcznie: psql -U wyszynski -d wyszynski_dzielazebrane -f migrations/add_p24_payments.sql
-- Idempotentne (IF NOT EXISTS), bezpieczne do wielokrotnego uruchomienia.
--
-- Dlaczego to w ogóle potrzebne:
-- Stripe przenosi `metadata` przez całą płatność i oddaje je w webhooku, więc
-- intencję (komu, ile kredytów, jaka rola, na ile dni) dało się trzymać po jego
-- stronie. Przelewy24 nie mają takiego pola — ich powiadomienie zwraca tylko
-- sessionId, orderId, kwotę i podpis. Jedynym nośnikiem intencji jest sessionId,
-- więc zamiar musi czekać w naszej bazie, a webhook odnajduje go po tym kluczu.

-- Intencja płatności: to, czego P24 nie przeniesie za nas.
-- jsonb, nie text — chcemy móc pytać po kluczach w razie audytu wpłat.
ALTER TABLE payment ADD COLUMN IF NOT EXISTS meta jsonb;

-- Okres dostępu w dniach. Dotąd zapisywaliśmy tylko period_months i dla VIP-a
-- (30 dni) szło przybliżenie „30 dni ≈ 1 miesiąc". Przy własnej integracji nie
-- ma powodu gubić dokładnej wartości — 0 znaczy „licz z period_months".
ALTER TABLE payment ADD COLUMN IF NOT EXISTS period_days integer NOT NULL DEFAULT 0;

-- Numer transakcji nadany przez P24 (przychodzi dopiero w powiadomieniu).
-- Potrzebny do transaction/verify, bez którego P24 NIE rozlicza środków.
--
-- MUSI być bigint, nie integer. P24 nadaje numery przekraczające zakres
-- 32-bitowy (zaobserwowane w sandboksie: 4302811017 przy limicie 2147483647).
-- Przy zbyt wąskiej kolumnie transakcja zostaje u P24 potwierdzona i środki
-- się rozliczają, a zapis u nas wybucha — czytelnik płaci i nie dostaje dostępu.
ALTER TABLE payment ADD COLUMN IF NOT EXISTS order_id bigint;
-- Dla baz, w których kolumna powstała wcześniej jako integer:
ALTER TABLE payment ALTER COLUMN order_id TYPE bigint;

-- 'stripe' | 'p24' — kolumna istnieje od początku, rozszerzamy tylko użycie.
COMMENT ON COLUMN payment.provider IS 'stripe | p24';

-- Nieopłacone zamiary starzeją się (klient zamknął bramkę). Indeks pod
-- ewentualne sprzątanie i pod podgląd „wiszących" płatności w panelu.
CREATE INDEX IF NOT EXISTS idx_payment_pending ON payment(status, created_at)
    WHERE status = 'pending';
