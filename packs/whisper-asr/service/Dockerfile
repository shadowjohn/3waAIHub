FROM nvidia/cuda:12.8.1-cudnn-runtime-ubuntu22.04

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        python3 \
        python3-pip \
        ffmpeg \
        libgomp1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY service/requirements.txt .
RUN python3 -m pip install --upgrade pip setuptools wheel \
    && python3 -m pip install --index-url https://download.pytorch.org/whl/cu128 \
        torch==2.8.0 torchaudio==2.8.0 \
    && python3 -m pip install -r requirements.txt \
    && python3 -m pip check

COPY service/app.py service/smoke.py service/storage_smoke.py service/test_app.py ./
RUN mkdir -p /tmp/home /tmp/xdg \
    && HOME=/tmp/home \
       XDG_CACHE_HOME=/tmp/xdg \
       python3 /app/smoke.py \
    && rm -rf /tmp/home /tmp/xdg

RUN python3 -m unittest -v test_app.py

COPY service/job.py service/offline_paths.py service/provision_offline_assets.py service/subtitle_reflow.py jobs/speech_transcribe.sh ./
RUN chmod 0755 /app/speech_transcribe.sh /app/provision_offline_assets.py \
    && ln -s /app/speech_transcribe.sh /app/speech-transcribe \
    && ln -s /app/provision_offline_assets.py /app/provision-offline-assets

EXPOSE 8000
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
