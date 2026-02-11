-- Full-text search for documents table
-- Run manually: psql -U wyszynski -d wyszynski_dzielazebrane -f migrations/add_fulltext_search.sql

-- Check if Polish text search config exists
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'polish') THEN
        RAISE NOTICE 'Polish text search config not found, using "simple" as fallback.';
    END IF;
END $$;

-- Add tsvector column if it doesn't exist
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'documents' AND column_name = 'search_vector'
    ) THEN
        ALTER TABLE documents ADD COLUMN search_vector tsvector;
    END IF;
END $$;

-- Populate search_vector (use 'polish' if available, 'simple' otherwise)
UPDATE documents SET search_vector =
    to_tsvector(
        CASE WHEN EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'polish') THEN 'polish'::regconfig ELSE 'simple'::regconfig END,
        coalesce(title, '') || ' ' || coalesce(subtitle, '') || ' ' || coalesce(content, '')
    );

-- Create GIN index for fast searches
DROP INDEX IF EXISTS idx_documents_search;
CREATE INDEX idx_documents_search ON documents USING GIN(search_vector);

-- Create trigger to auto-update search_vector on INSERT/UPDATE
-- Use a function-based approach for config flexibility
CREATE OR REPLACE FUNCTION documents_search_vector_update() RETURNS trigger AS $$
DECLARE
    config regconfig;
BEGIN
    IF EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'polish') THEN
        config := 'polish'::regconfig;
    ELSE
        config := 'simple'::regconfig;
    END IF;

    NEW.search_vector := to_tsvector(config,
        coalesce(NEW.title, '') || ' ' || coalesce(NEW.subtitle, '') || ' ' || coalesce(NEW.content, '')
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS documents_search_update ON documents;
CREATE TRIGGER documents_search_update
    BEFORE INSERT OR UPDATE ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_search_vector_update();
