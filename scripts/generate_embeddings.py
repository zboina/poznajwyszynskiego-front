#!/usr/bin/env python3
"""Generate embeddings for all documents using Ollama nomic-embed-text.
Writes directly to PostgreSQL via psycopg2."""

import json
import re
import sys
import time
import urllib.request
import psycopg2

DB_DSN = "host=127.0.0.1 dbname=wyszynski_dzielazebrane user=wyszynski password=wyszynski123"
OLLAMA_URL = "http://localhost:11434/api/embeddings"
MODEL = "all-minilm"
MAX_CHARS = 2000

def get_embedding(text: str) -> list | None:
    payload = json.dumps({"model": MODEL, "prompt": text}).encode()
    req = urllib.request.Request(OLLAMA_URL, data=payload, headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            data = json.loads(resp.read())
            return data.get("embedding")
    except Exception as e:
        print(f"\n  Error: {e}", file=sys.stderr)
        return None

def prepare_text(title, subtitle, content):
    parts = []
    if title:
        parts.append(title)
    if subtitle:
        parts.append(subtitle)
    if content:
        clean = re.sub(r'<[^>]+>', '', content)
        clean = re.sub(r'\s+', ' ', clean).strip()
        parts.append(clean)
    text = '. '.join(parts)
    return text[:MAX_CHARS] if len(text) > MAX_CHARS else text

def main():
    force = '--force' in sys.argv

    conn = psycopg2.connect(DB_DSN)
    cur = conn.cursor()

    where = "" if force else "WHERE embedding IS NULL"
    cur.execute(f"SELECT COUNT(*) FROM documents {where}")
    total = cur.fetchone()[0]

    if total == 0:
        print("Wszystkie dokumenty mają embeddingi. Użyj --force.")
        return

    print(f"Dokumentów: {total}")

    cur.execute(f"SELECT id, title, subtitle, content FROM documents {where} ORDER BY id")
    rows = cur.fetchall()

    processed = 0
    errors = 0
    t0 = time.time()

    for i, (doc_id, title, subtitle, content) in enumerate(rows):
        text = prepare_text(title, subtitle, content)
        embedding = get_embedding(text)

        if embedding is None:
            errors += 1
            print(f"\n  Błąd: ID={doc_id}")
        else:
            vec_str = '[' + ','.join(str(x) for x in embedding) + ']'
            cur.execute("UPDATE documents SET embedding = %s WHERE id = %s", (vec_str, doc_id))
            conn.commit()
            processed += 1

        elapsed = time.time() - t0
        per_doc = elapsed / (i + 1)
        remaining = per_doc * (total - i - 1)
        pct = (i + 1) / total * 100
        print(f"\r  {i+1}/{total} [{pct:5.1f}%] {elapsed:.0f}s elapsed, ~{remaining:.0f}s left, {per_doc:.1f}s/doc", end='', flush=True)

    print(f"\n\nGotowe: {processed}/{total}" + (f" (błędy: {errors})" if errors else ""))
    print(f"Czas: {time.time()-t0:.0f}s")

    cur.close()
    conn.close()

if __name__ == "__main__":
    main()
