-- Diacritic-insensitive Polish full-text search.
--
-- Problem: FTS na configu 'polish' jest wrażliwe na polskie znaki — "miłosc"
-- (bez ogonka) nie znajdował "miłość". Naiwne dodanie unaccent przed ispell
-- zabija lematyzację ("miłości" przestaje matchować "miłość").
--
-- Rozwiązanie: search_vector przechowuje lexemy z DWÓCH configów:
--   * 'polish'          — pełna lematyzacja (z diakrytykami),
--   * 'polish_unaccent' — wariant bez ogonków (odporny na brak diakrytyków).
-- Zapytania OR-ują oba configi (patrz DocumentRepository::search, ChunkRetriever).
--
-- Wymaga rozszerzenia unaccent (jest instalowane razem z bazą).

-- 1) Config odporny na diakrytyki: unaccent normalizuje token, potem polish_ispell/simple.
DROP TEXT SEARCH CONFIGURATION IF EXISTS polish_unaccent;
CREATE TEXT SEARCH CONFIGURATION polish_unaccent (COPY = polish);
ALTER TEXT SEARCH CONFIGURATION polish_unaccent
  ALTER MAPPING FOR asciiword, asciihword, hword_asciipart, word, hword, hword_part
  WITH unaccent, polish_ispell, simple;

-- 2) documents: trigger składa lexemy z obu configów (te same wagi A/B/C/D).
CREATE OR REPLACE FUNCTION public.documents_search_vector_update()
RETURNS trigger LANGUAGE plpgsql AS $function$
BEGIN
    NEW.search_vector :=
        setweight(to_tsvector('polish',          COALESCE(NEW.title, '')),    'A') ||
        setweight(to_tsvector('polish',          COALESCE(NEW.subtitle, '')), 'B') ||
        setweight(to_tsvector('polish',          COALESCE(NEW.content, '')),  'C') ||
        setweight(to_tsvector('polish',          COALESCE(NEW.location, '')), 'D') ||
        setweight(to_tsvector('polish_unaccent', COALESCE(NEW.title, '')),    'A') ||
        setweight(to_tsvector('polish_unaccent', COALESCE(NEW.subtitle, '')), 'B') ||
        setweight(to_tsvector('polish_unaccent', COALESCE(NEW.content, '')),  'C') ||
        setweight(to_tsvector('polish_unaccent', COALESCE(NEW.location, '')), 'D');
    RETURN NEW;
END;
$function$;

-- 3) document_chunks: ten sam pomysł (gałąź FTS asystenta RAG).
CREATE OR REPLACE FUNCTION public.document_chunks_search_update()
RETURNS trigger LANGUAGE plpgsql AS $function$
BEGIN
    NEW.search_vector :=
        to_tsvector('polish',          coalesce(NEW.content, '')) ||
        to_tsvector('polish_unaccent', coalesce(NEW.content, ''));
    RETURN NEW;
END;
$function$;

-- 4) Przebudowa istniejących wierszy przez nowe triggery (no-op UPDATE odpala BEFORE trigger).
UPDATE documents       SET title   = title;
UPDATE document_chunks SET content = content;
