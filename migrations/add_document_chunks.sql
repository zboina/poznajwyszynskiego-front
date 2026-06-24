-- RAG chunk store for hybrid retrieval (semantic bge-m3 1024-dim + Polish FTS)
-- Run manually: psql -U wyszynski -d wyszynski_dzielazebrane -f migrations/add_document_chunks.sql

CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS document_chunks (
    id            bigserial PRIMARY KEY,
    document_id   integer NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    volume_id     integer NOT NULL,
    chunk_index   integer NOT NULL,
    content       text    NOT NULL,
    page_start    integer,          -- printed page where the chunk begins (from page_breaks)
    page_end      integer,          -- printed page where the chunk ends
    char_count    integer NOT NULL DEFAULT 0,
    embedding     vector(1024),     -- bge-m3
    search_vector tsvector,
    created_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (document_id, chunk_index)
);

CREATE INDEX IF NOT EXISTS idx_chunks_document  ON document_chunks(document_id);
CREATE INDEX IF NOT EXISTS idx_chunks_volume    ON document_chunks(volume_id);
CREATE INDEX IF NOT EXISTS idx_chunks_search    ON document_chunks USING GIN(search_vector);
-- HNSW: fast approximate cosine NN (pgvector >= 0.5)
CREATE INDEX IF NOT EXISTS idx_chunks_embedding ON document_chunks USING hnsw (embedding vector_cosine_ops);

-- Keep the chunk FTS vector in sync (polish config, simple fallback)
CREATE OR REPLACE FUNCTION document_chunks_search_update() RETURNS trigger AS $$
DECLARE config regconfig;
BEGIN
    IF EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'polish') THEN
        config := 'polish'::regconfig;
    ELSE
        config := 'simple'::regconfig;
    END IF;
    NEW.search_vector := to_tsvector(config, coalesce(NEW.content, ''));
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS document_chunks_search_trg ON document_chunks;
CREATE TRIGGER document_chunks_search_trg
    BEFORE INSERT OR UPDATE ON document_chunks
    FOR EACH ROW EXECUTE FUNCTION document_chunks_search_update();
