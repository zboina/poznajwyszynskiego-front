-- Historia rozmów asystenta per konto — schemat „treść raz + wskaźnik”.
-- Odpowiedzi zapisywane RAZ w rag_answer (deduplikacja po hashu zakresu+epoki+pytania,
-- niezmienne — to jest „odpowiedź, za którą użytkownik zapłacił”), a rag_query (log
-- per użytkownik) wskazuje na nie kolumną answer_id. N userów z tym samym pytaniem
-- = 1 wiersz rag_answer + N malutkich wskaźników.
-- Uruchom: psql -U wyszynski -d wyszynski_dzielazebrane -f migrations/add_rag_history.sql

CREATE TABLE IF NOT EXISTS rag_answer (
    id           bigserial PRIMARY KEY,
    content_hash char(64) NOT NULL UNIQUE,        -- = klucz RagCache (zakres+epoka+znorm. pytanie)
    question     text NOT NULL,
    volume_id    integer,                         -- NULL = wszystkie tomy
    answer       text NOT NULL,
    citations    jsonb NOT NULL DEFAULT '[]'::jsonb,
    created_at   timestamptz NOT NULL DEFAULT now()
);

-- Wskaźnik z logu zapytań na trwałą odpowiedź + przypięcie („wartościowe”).
-- ON DELETE SET NULL: usunięcie treści odpowiedzi nie kasuje wiersza finansowego.
ALTER TABLE rag_query ADD COLUMN IF NOT EXISTS answer_id bigint REFERENCES rag_answer(id) ON DELETE SET NULL;
ALTER TABLE rag_query ADD COLUMN IF NOT EXISTS pinned    boolean NOT NULL DEFAULT false;
ALTER TABLE rag_query ADD COLUMN IF NOT EXISTS pinned_at timestamptz;

-- Historia danego konta: najpierw przypięte, potem najnowsze.
CREATE INDEX IF NOT EXISTS idx_rag_query_history ON rag_query (user_id, pinned DESC, created_at DESC) WHERE answer_id IS NOT NULL;
