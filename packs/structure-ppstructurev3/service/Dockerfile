FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libgl1 libglib2.0-0 libgomp1 \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN python3 -m pip install --upgrade pip setuptools wheel \
    && python3 -m pip install -r requirements.txt \
    && python3 -m pip check

COPY smoke.py storage_smoke.py .
RUN python3 /app/smoke.py

COPY app.py .

EXPOSE 8000
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
