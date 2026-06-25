-- Rozszerzenie logów asystenta AI: treść pytania, źródło (cache/AI) i koszt w kredytach.
ALTER TABLE rag_query ADD COLUMN IF NOT EXISTS question     TEXT;
ALTER TABLE rag_query ADD COLUMN IF NOT EXISTS cached       BOOLEAN  NOT NULL DEFAULT false;
ALTER TABLE rag_query ADD COLUMN IF NOT EXISTS credits_cost SMALLINT NOT NULL DEFAULT 0;
