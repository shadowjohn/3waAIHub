FROM nvidia/cuda:12.9.0-cudnn-runtime-ubuntu22.04

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-pip \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN python3 -m pip install --upgrade pip \
    && python3 -m pip install -r requirements.txt \
    && python3 -m pip check

COPY acceptance.py app.py image_pipeline.py inference_smoke.py model_runtime.py model_smoke.py provision_offline_assets.py \
    smoke.py storage_smoke.py test_app.py test_endpoint.py test_image_pipeline.py test_provision_offline_assets.py ./
RUN mkdir -p /tmp/home /tmp/hf /tmp/xdg \
    && HOME=/tmp/home HF_HOME=/tmp/hf XDG_CACHE_HOME=/tmp/xdg python3 /app/smoke.py \
    && python3 -m unittest -v test_app.py test_endpoint.py test_image_pipeline.py test_provision_offline_assets.py \
    && rm -rf /tmp/home /tmp/hf /tmp/xdg \
    && chmod 0555 /app/*.py

USER 65532:65532
EXPOSE 8000
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
