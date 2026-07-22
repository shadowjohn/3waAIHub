FROM nvidia/cuda:12.4.1-cudnn-runtime-ubuntu22.04

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
RUN python3 -m pip install --break-system-packages --upgrade pip setuptools wheel \
    && python3 -m pip install --break-system-packages -r requirements.txt \
    && python3 -m pip check

COPY service/app.py service/smoke.py service/storage_smoke.py service/test_app.py .
RUN mkdir -p /tmp/home /tmp/xdg \
    && HOME=/tmp/home \
       XDG_CACHE_HOME=/tmp/xdg \
       python3 /app/smoke.py \
    && rm -rf /tmp/home /tmp/xdg

RUN python3 -m unittest -v test_app.py

COPY service/job.py service/offline_paths.py service/provision_offline_assets.py jobs/speech_transcribe.sh ./
RUN chmod 0755 /app/speech_transcribe.sh /app/provision_offline_assets.py \
    && ln -s /app/speech_transcribe.sh /app/speech-transcribe \
    && ln -s /app/provision_offline_assets.py /app/provision-offline-assets

EXPOSE 8000
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
