FROM python:3.11-slim

ENV PIP_NO_CACHE_DIR=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates ffmpeg util-linux \
    && groupadd --gid 10001 runner \
    && useradd --uid 10001 --gid runner --create-home --shell /usr/sbin/nologin runner \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN python3 -m pip install --no-cache-dir -r requirements.txt \
    && python3 -m pip check

COPY job.py provision_offline_assets.py inference_smoke.py ./
RUN chmod 0755 /app/job.py /app/provision_offline_assets.py \
    && ln -s /app/job.py /app/speech-fast-zh \
    && ln -s /app/provision_offline_assets.py /app/provision-offline-assets

USER root
ENTRYPOINT ["/app/speech-fast-zh"]
