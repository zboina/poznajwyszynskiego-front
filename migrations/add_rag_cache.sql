-- Cache odpowiedzi asystenta: identyczne pytanie (per zakres tomu) serwowane z bazy,
-- bez ponownego wywołania płatnego modelu. Uruchom:
-- psql -U wyszynski -d wyszynski_dzielazebrane -f migrations/add_rag_cache.sql
CREATE TABLE IF NOT EXISTS rag_cache (
    id          bigserial PRIMARY KEY,
    q_hash      char(64) NOT NULL UNIQUE,         -- sha256(volume_id|znormalizowane_pytanie)
    q_norm      text NOT NULL,
    volume_id   integer,                          -- NULL = wszystkie tomy
    question    text NOT NULL,
    answer      text NOT NULL,
    citations   jsonb NOT NULL DEFAULT '[]'::jsonb,
    hits        integer NOT NULL DEFAULT 0,
    created_at  timestamptz NOT NULL DEFAULT now(),
    last_hit_at timestamptz
);
