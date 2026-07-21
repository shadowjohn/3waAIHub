FROM nvidia/cuda:12.4.1-cudnn-runtime-ubuntu22.04

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    AIHUB_DEMUCS_MODEL_DIR=/opt/aihub/models/demucs \
    AIHUB_DEEPFILTERNET_MODEL_DIR=/opt/aihub/models/deepfilternet \
    TORCH_HOME=/opt/aihub/models/torch

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-pip ffmpeg ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt /tmp/requirements.txt
RUN python3 -m pip install --no-cache-dir -r /tmp/requirements.txt

COPY model-lock.sha256 /tmp/model-lock.sha256
RUN set -eux; \
    mkdir -p "$AIHUB_DEMUCS_MODEL_DIR" "$TORCH_HOME"; \
    while read -r checksum file; do \
        curl --fail --location --retry 3 --output "$AIHUB_DEMUCS_MODEL_DIR/$file" "https://dl.fbaipublicfiles.com/demucs/hybrid_transformer/$file"; \
    done < /tmp/model-lock.sha256; \
    cd "$AIHUB_DEMUCS_MODEL_DIR"; sha256sum -c /tmp/model-lock.sha256; \
    printf '%s\n' "models: ['955717e8']" > "$AIHUB_DEMUCS_MODEL_DIR/htdemucs.yaml"; \
    printf '%s\n' "models: ['f7e0c4bc', 'd12395a8', '92cfc3b6', '04573f0d']" "weights: [[1., 0., 0., 0.], [0., 1., 0., 0.], [0., 0., 1., 0.], [0., 0., 0., 1.]]" > "$AIHUB_DEMUCS_MODEL_DIR/htdemucs_ft.yaml"

COPY --chmod=755 job.py /app/audio-cleanup
ENTRYPOINT ["/app/audio-cleanup"]
