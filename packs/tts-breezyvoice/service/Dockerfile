FROM nvidia/cuda:11.8.0-cudnn8-runtime-ubuntu22.04

ARG BREEZYVOICE_UPSTREAM_REVISION

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates git python3 python3-pip libsndfile1 \
    && python3 -c 'import sys; assert sys.version_info[:2] == (3, 10)' \
    && rm -rf /var/lib/apt/lists/*

RUN set -eu; \
    case "$BREEZYVOICE_UPSTREAM_REVISION" in ''|main|latest|*/*) exit 64 ;; esac; \
    printf '%s' "$BREEZYVOICE_UPSTREAM_REVISION" | grep -Eq '^[0-9a-f]{40}$'; \
    git clone https://github.com/mtkresearch/BreezyVoice.git /opt/breezyvoice; \
    git -C /opt/breezyvoice checkout --detach "$BREEZYVOICE_UPSTREAM_REVISION"; \
    test "$(git -C /opt/breezyvoice rev-parse HEAD)" = "$BREEZYVOICE_UPSTREAM_REVISION"

COPY service/requirements.txt ./requirements.txt
RUN python3 -m pip install --no-cache-dir -r requirements.txt \
    && python3 -m pip check

COPY service/job.py service/provision.py ./
COPY jobs/voice_generate.sh jobs/provision_models.sh ./
RUN chmod 0755 /app/job.py /app/provision.py /app/voice_generate.sh /app/provision_models.sh \
    && ln -s /app/voice_generate.sh /app/breezyvoice-synthesize \
    && ln -s /app/provision_models.sh /app/breezyvoice-provision-models

ENTRYPOINT ["/app/voice_generate.sh"]
