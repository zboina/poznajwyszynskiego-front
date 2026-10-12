-- Wektory PolDense-150M (OPI-PIB) obok dotychczasowych bge-m3.
-- Kolumna embedding (bge-m3) zostaje — powrót = EMBEDDING_MODEL=bge-m3.
-- Wypełnianie: php bin/console app:embed-chunks; indeks po wypełnieniu.
ALTER TABLE document_chunks ADD COLUMN IF NOT EXISTS embedding_pd vector(768);

-- Po app:embed-chunks:
-- CREATE INDEX IF NOT EXISTS idx_chunks_embedding_pd ON document_chunks USING hnsw (embedding_pd vector_cosine_ops);
