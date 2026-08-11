FROM nvidia/cuda:12.9.0-cudnn-runtime-ubuntu22.04

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app
RUN apt-get update \
    && apt-get install -y --no-install-recommends git python3 python3-pip \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN python3 -m pip install --upgrade pip setuptools wheel \
    && python3 -m pip install --extra-index-url https://download.pytorch.org/whl/cu128 torch==2.9.1+cu128 torchvision==0.24.1+cu128 \
    && python3 -m pip install --no-build-isolation -r requirements.txt \
    && python3 -m pip check

COPY image_contract.py model_runtime.py test_image_contract.py test_model_runtime.py test_upscale_runner.py ./
RUN python3 -m unittest -v test_image_contract.py test_model_runtime.py test_upscale_runner.py \
    && chmod 0555 /app/*.py

USER 65532:65532
