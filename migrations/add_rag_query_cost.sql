-- Realny koszt zapytań AI: model, zużyte tokeny i koszt w USD (z OpenRouter usage accounting).
ALTER TABLE rag_query ADD COLUMN IF NOT EXISTS model         VARCHAR(64);
ALTER TABLE rag_query ADD COLUMN IF NOT EXISTS input_tokens  INTEGER       NOT NULL DEFAULT 0;
ALTER TABLE rag_query ADD COLUMN IF NOT EXISTS output_tokens INTEGER       NOT NULL DEFAULT 0;
ALTER TABLE rag_query ADD COLUMN IF NOT EXISTS cost_usd      NUMERIC(12,6) NOT NULL DEFAULT 0;
