"""Embeddingi PolDense (OPI-PIB) dla asystenta AI (poznajwyszynskiego.pl).

Zastępuje bge-m3 z Ollamy. Pytania dostają prefiks "[query]: ", fragmenty
tekstu — żaden (karta modelu). Wektory są normalizowane (cosinus = iloczyn).
Działa lokalnie, tylko na 127.0.0.1.
"""
import os
import time

import torch
from fastapi import FastAPI
from pydantic import BaseModel
from sentence_transformers import SentenceTransformer

MODEL = os.environ.get("EMBED_MODEL", "OPI-PIB/PolDense-150M")
MAX_LENGTH = int(os.environ.get("EMBED_MAX_LENGTH", "1024"))
QUERY_PREFIX = "[query]: "
torch.set_num_threads(int(os.environ.get("EMBED_THREADS", "3")))

model = SentenceTransformer(MODEL, device="cpu", model_kwargs={"dtype": torch.bfloat16})
model.max_seq_length = MAX_LENGTH
app = FastAPI()


class EmbedRequest(BaseModel):
    texts: list[str]
    kind: str = "document"  # "query" albo "document"


@app.post("/embed")
def embed(req: EmbedRequest):
    t0 = time.perf_counter()
    texts = [QUERY_PREFIX + t for t in req.texts] if req.kind == "query" else req.texts
    with torch.inference_mode():
        vecs = model.encode(texts, batch_size=8, normalize_embeddings=True, convert_to_numpy=True)
    return {
        "embeddings": [[round(float(x), 6) for x in v] for v in vecs],
        "model": MODEL,
        "dim": int(vecs.shape[1]) if len(texts) else 0,
        "ms": round((time.perf_counter() - t0) * 1000),
    }


@app.get("/health")
def health():
    return {"ok": True, "model": MODEL, "dim": model.get_sentence_embedding_dimension()}
