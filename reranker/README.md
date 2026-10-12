# Reranker i embeddingi asystenta AI

Jeden obraz `pw-reranker`, dwie usługi (obie tylko na 127.0.0.1):

- `pw-reranker` (port 8002, app.py) — porządkuje znalezione fragmenty,
- `pw-embed` (port 8004, embed.py) — wektory OPI-PIB/PolDense-150M dla pytań
  (prefiks `[query]: `) i fragmentów; front: `EMBEDDING_MODEL=poldense`,
  kolumna `document_chunks.embedding_pd`, uzupełnianie `app:embed-chunks`.

    docker run -d --name pw-embed --restart unless-stopped \
      -p 127.0.0.1:8004:8000 -v pw-reranker-models:/models \
      --memory 2g --cpus 3 pw-reranker uvicorn embed:app --host 0.0.0.0 --port 8000

## Reranker

Polski cross-encoder porządkujący fragmenty znalezione przez `ChunkRetriever`
(pgvector + pełnotekst) przed wysłaniem ich do modelu odpowiadającego.

    docker build -t pw-reranker reranker/
    docker run -d --name pw-reranker --restart unless-stopped \
      -p 127.0.0.1:8002:8000 -v pw-reranker-models:/models \
      --memory 2g --cpus 3 pw-reranker

Front czyta adres z env `RERANKER_URL` (pusty = bez rerankera). Gdy usługa nie
odpowiada, asystent używa kolejności z wyszukiwania hybrydowego.
Jakość mierzy `app:rag-eval` (rag-eval/).

## Pomiary (12.10.2026, EPYC 4 vCPU bez GPU, 3 rdzenie dla kontenera)

Czas oceny 20 fragmentów i wynik na zestawie rag-eval (punkt wyjścia: hybryda
bez rerankera, @1 38,4%, @8 68,7%, pytania ogólne 4,56/8 na temat):

| model | precyzja | czas | @1 | @8 | ogólne |
|---|---|---|---|---|---|
| polish-reranker-base-ranknet (Apache-2.0) | bf16 | ~3 s | 46,5% | 71,7% | 4,32 |
| polish-reranker-roberta-v3 (Gemma) | bf16 | ~9 s | 53,5% | 72,7% | 4,91 |

int8 nie przyspiesza względem bf16 (AVX-512 BF16), fp32 jest 2,5–3× wolniejsze.
Włączony jest base. Granicę wyznacza @20 = 74,7%: reranker nie wyciągnie
fragmentu, którego hybryda nie znalazła — dalszy zysk to lepsze embeddingi.
Ocena rerankera nie rozdziela pytań bez odpowiedzi w korpusie (nie nadaje się
na próg „nie wiem”).

## PolDense zamiast bge-m3 (13.10.2026)

Wszystkie 10 258 fragmentów przeliczone (`app:embed-chunks`, 86 min), indeks
HNSW `idx_chunks_embedding_pd`. Przy okazji: domyślne `hnsw.ef_search = 40`
obcinało gałąź semantyczną do 40 kandydatów — retriever ustawia teraz 200.
Pomiar w tych samych warunkach (ef_search 200):

| embeddingi | reranker | @1 | @8 | @20 | MRR | tekst@8 | sem@8 | ogólne (na temat / wprost) |
|---|---|---|---|---|---|---|---|---|
| bge-m3 | — | 37,4% | 67,7% | 74,7% | 0,487 | 74,7% | 60,6% | — |
| PolDense | — | 40,4% | 65,7% | 77,8% | 0,486 | 73,7% | 53,5% | — |
| bge-m3 | base | 46,5% | 71,7% | 74,7% | 0,540 | 76,8% | 60,6% | 4,38 / 2,53 |
| **PolDense** | base | 45,5% | **73,7%** | **77,8%** | **0,546** | **79,8%** | 53,5% | **4,82 / 2,94** |

Sam PolDense trafia rzadziej w pierwszą ósemkę, ale znajduje więcej właściwych
fragmentów w szerszym oknie (@20), a reranker wyciąga je wyżej. Na prawdziwych
pytaniach czytelników daje wyraźnie lepsze fragmenty. Włączony przez
`EMBEDDING_MODEL=poldense` w `.env.local`; powrót do bge-m3 = usunięcie tej
linijki (kolumna `embedding` jest nadal aktualizowana przez `app:build-chunks`).
