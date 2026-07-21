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

RUN set -eux; \
    mkdir -p "$AIHUB_DEMUCS_MODEL_DIR" "$TORCH_HOME"; \
    fetch_model() { \
        file="$1"; checksum="$2"; \
        curl --fail --location --retry 3 --output "$AIHUB_DEMUCS_MODEL_DIR/$file" "https://dl.fbaipublicfiles.com/demucs/hybrid_transformer/$file"; \
        test "$(sha256sum "$AIHUB_DEMUCS_MODEL_DIR/$file" | awk '{print substr($1, 1, 8)}')" = "$checksum"; \
    }; \
    fetch_model 955717e8-8726e21a.th 8726e21a; \
    fetch_model f7e0c4bc-ba3fe64a.th ba3fe64a; \
    fetch_model d12395a8-e57c48e6.th e57c48e6; \
    fetch_model 92cfc3b6-ef3bcb9c.th ef3bcb9c; \
    fetch_model 04573f0d-f3cf25b2.th f3cf25b2; \
    printf '%s\n' "models: ['955717e8']" > "$AIHUB_DEMUCS_MODEL_DIR/htdemucs.yaml"; \
    printf '%s\n' "models: ['f7e0c4bc', 'd12395a8', '92cfc3b6', '04573f0d']" "weights: [[1., 0., 0., 0.], [0., 1., 0., 0.], [0., 0., 1., 0.], [0., 0., 0., 1.]]" > "$AIHUB_DEMUCS_MODEL_DIR/htdemucs_ft.yaml"

COPY --chmod=755 job.py /app/audio-cleanup
ENTRYPOINT ["/app/audio-cleanup"]
