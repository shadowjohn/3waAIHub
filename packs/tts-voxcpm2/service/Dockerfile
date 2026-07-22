FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends gcc g++ libsndfile1 ffmpeg \
    && rm -rf /var/lib/apt/lists/*

COPY service/requirements.txt .
RUN python3 -m pip install --upgrade pip setuptools wheel \
    && python3 -m pip install --index-url https://download.pytorch.org/whl/cu128 \
        torch==2.8.0 torchaudio==2.8.0 \
    && python3 -m pip install -r requirements.txt \
    && python3 -m pip check

COPY service/smoke.py service/storage_smoke.py service/long_form.py service/long_form_smoke.py ./
RUN python3 /app/smoke.py
RUN python3 /app/long_form_smoke.py

COPY service/app.py service/job.py service/test_app.py ./
COPY jobs/voice_generate.sh ./voice_generate.sh
RUN python3 -m unittest -v test_app.py \
    && chmod 0755 /app/voice_generate.sh \
    && ln -s /app/voice_generate.sh /app/voice-generate

EXPOSE 8000
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
