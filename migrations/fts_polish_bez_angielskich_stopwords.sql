-- Słownik polish_ispell miał stopwords = 'english': z indeksu pełnotekstowego
-- wypadały polskie słowa pokrywające się z angielską listą (i, a, do, by, on, no,
-- my, we, most, same, …). Fraza "matka i ojciec" zamieniała się w
-- 'matka' <2> 'ojciec' (dowolne słowo w środku), a słowa "most" nie dało się
-- znaleźć wcale. Usuwamy listę i przeliczamy wektory (treść się nie zmienia —
-- triggery liczą search_vector z bieżących kolumn).
-- Kopia przed: /home/www/backups/wyszynski_przed_stopwords_20261013_173356.dump
-- Wykonane 13.10.2026.
BEGIN;
ALTER TEXT SEARCH DICTIONARY polish_ispell (StopWords);
UPDATE documents SET search_vector = NULL;
UPDATE document_chunks SET search_vector = NULL;
COMMIT;

-- Masowy UPDATE przepisuje wszystkie wiersze document_chunks, a indeks HNSW
-- traci wtedy trafność (VACUUM nie wystarcza: w teście 2 pytania wypadły z top-8).
-- Po każdym takim przeliczeniu przebudować indeksy wektorowe:
REINDEX INDEX CONCURRENTLY idx_chunks_embedding_pd;
REINDEX INDEX CONCURRENTLY idx_chunks_embedding;
