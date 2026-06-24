# Asystent AI (RAG) — wdrożenie na produkcji

Asystent pod `/asystent` odpowiada **wyłącznie** na podstawie korpusu Dzieł Zebranych,
z cytatami do tomu i strony. Embeddingi liczone są **lokalnie** (Ollama / `bge-m3`) —
teksty kardynała nie wychodzą poza serwer; do OpenRouter idzie tylko pytanie + znalezione
fragmenty (do wygenerowania odpowiedzi).

Poniżej kroki do wykonania **na serwerze produkcyjnym po `git pull`**.

---

## 1. Kod

```bash
git pull origin main
# Nie dodano nowych zależności composera — `composer install` opcjonalnie.
```

## 2. pgvector + tabela chunków (jednorazowo)

Rozszerzenie `vector` wymaga superusera Postgresa. Najpierw zainstaluj pakiet pgvector
dla wersji serwera (np. `apt install postgresql-16-pgvector`), potem:

```bash
# rozszerzenie jako superuser
sudo -u postgres psql -d <NAZWA_BAZY_PROD> -c 'CREATE EXTENSION IF NOT EXISTS vector;'

# tabela document_chunks + indeksy (HNSW + GIN + trigger FTS)
psql -U <USER_PROD> -d <NAZWA_BAZY_PROD> -f migrations/add_document_chunks.sql
```

## 3. Ollama + model embeddingów (WYMAGANE)

Embeddingi liczy lokalna Ollama — potrzebna **i przy budowaniu chunków, i przy każdym
pytaniu** (pytanie jest wektoryzowane w locie). Bez niej retrieval spadnie do samego
wyszukiwania pełnotekstowego (gorsza jakość).

```bash
# instalacja: https://ollama.com/download (CPU wystarcza, GPU niepotrzebne)
ollama pull bge-m3
# upewnij się, że usługa słucha na localhost:11434 i startuje z systemem
systemctl enable --now ollama   # lub odpowiednik
```

## 4. Zmienne środowiskowe

W `.env` jest już blok RAG (`RAG_LLM_PROVIDER=openrouter`, `RAG_LLM_MODEL=google/gemini-2.5-flash`).
**Klucz API ustaw w `.env.local`** (NIE w `.env`, nie commituj):

```dotenv
# frontend/.env.local
OPENROUTER_API_KEY=sk-or-...        # klucz z openrouter.ai
```

> Bez klucza kod automatycznie spada na lokalną Ollamę do generacji (`llama3.1:8b`),
> ale na CPU to ~kilka minut na odpowiedź — do produkcji zalecany OpenRouter.

Jeśli używacie `composer dump-env prod`, uruchom je po edycji env.

## 5. Zbudowanie chunków dla tomów

Dla każdego tomu, który ma być dostępny w asystencie (np. opublikowane 1, 12, 13, 14):

```bash
php bin/console app:build-chunks --volume=1
php bin/console app:build-chunks --volume=12
php bin/console app:build-chunks --volume=13
php bin/console app:build-chunks --volume=14
```

- ~1 chunk/s (CPU) → **~20 min na tom**. Można puścić w tle (`nohup ... &`).
- Uruchamiaj **sekwencyjnie** (równoległe biją się o CPU Ollamy).
- Wymaga `pdf_page_start` + `page_breaks` w dokumentach dla cytowania stronowego.
- Asystent pokazuje w nagłówku i selektorze zakresu **tylko tomy, które mają chunki** —
  lista aktualizuje się automatycznie.

## 6. Assety (WAŻNE — częsty błąd)

`public/assets/` jest w `.gitignore`, więc skompilowane pliki **nie przychodzą z repo**.
Po każdym wdrożeniu zmian w `assets/` przekompiluj:

```bash
php bin/console asset-map:compile
```

Inaczej przeglądarka dostanie stare pliki JS (objaw: „kliknięcie w źródło nie podświetla
fragmentu"). Ikony asystenta używają webfontu Tabler Icons doładowanego z CDN w szablonie —
nie wymagają nic poza dostępem do sieci.

## 7. Cache + OPcache

```bash
APP_ENV=prod php bin/console cache:clear
# jeśli OPcache ma validate_timestamps=0 (typowe na prod) — przeładuj PHP-FPM:
sudo systemctl reload php8.3-fpm
```

---

## Uwagi

- **Dostęp:** `/asystent` jest w `security.yaml` jako `PUBLIC_ACCESS`, ale kontroler bramkuje
  treść przez logowanie lub tryb demo (`setting.demo_enabled`).
- **Limit podglądów:** podgląd źródła w modalu korzysta z `/dokument/{id}/tresc`, który dla
  zwykłego użytkownika (ROLE_USER) liczy się do limitu 5 podglądów/24 h. Donator/VIP — bez limitu.
- **Historia rozmów** jest po stronie przeglądarki (`localStorage`) — nie wymaga niczego na serwerze
  i nie jest współdzielona między urządzeniami.
- **Prywatność:** embeddingi i wyszukiwanie są lokalne; do OpenRouter trafia tylko treść pytania
  i wybranych fragmentów na czas generacji odpowiedzi.
