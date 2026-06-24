-- Faza 1+2: rejestracja kont (weryfikacja e-mail), czasowy dostęp płatny i metering zapytań AI.
-- Uruchom ręcznie: psql -U wyszynski -d wyszynski_dzielazebrane -f migrations/add_user_access_and_payments.sql
-- Idempotentne (IF NOT EXISTS), bezpieczne do wielokrotnego uruchomienia.

-- ── User: weryfikacja e-mail + czasowy dostęp płatny ──
ALTER TABLE "user" ADD COLUMN IF NOT EXISTS is_verified boolean NOT NULL DEFAULT false;
ALTER TABLE "user" ADD COLUMN IF NOT EXISTS verification_token varchar(64);
ALTER TABLE "user" ADD COLUMN IF NOT EXISTS verification_expires_at timestamp(0) without time zone;
ALTER TABLE "user" ADD COLUMN IF NOT EXISTS access_expires_at timestamp(0) without time zone;
ALTER TABLE "user" ADD COLUMN IF NOT EXISTS granted_role varchar(32);
ALTER TABLE "user" ADD COLUMN IF NOT EXISTS stripe_customer_id varchar(255);

-- Istniejące konta (admin, demo) traktujemy jako zweryfikowane, by ich nie zablokować.
UPDATE "user" SET is_verified = true WHERE is_verified = false;

-- ── Płatności (darowizny) ──
CREATE TABLE IF NOT EXISTS payment (
    id            bigserial PRIMARY KEY,
    user_id       integer NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
    amount        integer NOT NULL,                       -- w groszach
    currency      varchar(3)  NOT NULL DEFAULT 'pln',
    status        varchar(16) NOT NULL DEFAULT 'pending', -- pending|paid|failed|canceled
    provider      varchar(16) NOT NULL DEFAULT 'stripe',
    external_id   varchar(255),                           -- Stripe Checkout Session / PaymentIntent
    granted_role  varchar(32),                            -- ROLE_DONATOR | ROLE_VIP
    period_months integer NOT NULL DEFAULT 12,
    created_at    timestamp(0) without time zone NOT NULL DEFAULT now(),
    paid_at       timestamp(0) without time zone
);
CREATE INDEX IF NOT EXISTS idx_payment_user ON payment(user_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_external ON payment(external_id) WHERE external_id IS NOT NULL;

-- ── Metering zapytań do asystenta AI (kontrola kosztu generacji) ──
CREATE TABLE IF NOT EXISTS rag_query (
    id         bigserial PRIMARY KEY,
    user_id    integer NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
    volume_id  integer,
    created_at timestamp(0) without time zone NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_rag_query_user_time ON rag_query(user_id, created_at);
