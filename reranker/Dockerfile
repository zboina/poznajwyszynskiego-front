FROM python:3.12-slim
RUN pip install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu \
 && pip install --no-cache-dir "sentence-transformers>=5" fastapi "uvicorn[standard]"
WORKDIR /app
COPY app.py .
ENV HF_HOME=/models
EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]
