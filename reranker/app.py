"""Reranker fragmentów dla asystenta AI (poznajwyszynskiego.pl).

Cross-encoder czyta pytanie razem z każdym fragmentem i zwraca ocenę trafności
(surowy logit: im wyżej, tym trafniej). Działa lokalnie, tylko na 127.0.0.1.
"""
import os
import time

import torch
from fastapi import FastAPI
from pydantic import BaseModel
from sentence_transformers import CrossEncoder

MODEL = os.environ.get("RERANKER_MODEL", "sdadas/polish-reranker-base-ranknet")
MAX_LENGTH = int(os.environ.get("RERANKER_MAX_LENGTH", "512"))
# Precyzja: "bf16" (AVX-512 BF16 na EPYC), "int8" (kwantyzacja dynamiczna, VNNI) albo "fp32".
PRECISION = os.environ.get("RERANKER_PRECISION", "bf16")
torch.set_num_threads(int(os.environ.get("RERANKER_THREADS", "3")))


def load() -> CrossEncoder:
    common = dict(activation_fn=torch.nn.Identity(), max_length=MAX_LENGTH, device="cpu")
    if PRECISION == "bf16":
        return CrossEncoder(MODEL, model_kwargs={"dtype": torch.bfloat16}, **common)
    if PRECISION == "int8":
        # Kwantyzacja dynamiczna warstw liniowych (wagi int8, VNNI); bez eksportu do ONNX.
        m = CrossEncoder(MODEL, **common)
        target = m if isinstance(m, torch.nn.Module) else m.model
        torch.ao.quantization.quantize_dynamic(target, {torch.nn.Linear}, dtype=torch.qint8, inplace=True)
        return m
    return CrossEncoder(MODEL, **common)


model = load()
app = FastAPI()


class RerankRequest(BaseModel):
    query: str
    documents: list[str]


@app.post("/rerank")
def rerank(req: RerankRequest):
    t0 = time.perf_counter()
    if not req.documents:
        return {"scores": [], "model": MODEL, "ms": 0}
    with torch.inference_mode():
        scores = model.predict([(req.query, d) for d in req.documents], batch_size=8)
    return {"scores": [float(s) for s in scores], "model": MODEL, "ms": round((time.perf_counter() - t0) * 1000)}


@app.get("/health")
def health():
    return {"ok": True, "model": MODEL, "precision": PRECISION}
