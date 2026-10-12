# Reranker asystenta AI

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
