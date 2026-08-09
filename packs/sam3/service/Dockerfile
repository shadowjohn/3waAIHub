FROM nvidia/cuda:12.8.1-cudnn-runtime-ubuntu24.04

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ffmpeg \
        git \
        libglib2.0-0 \
        libgl1 \
        python3.12 \
        python3-pip \
    && rm -rf /var/lib/apt/lists/* \
    && ln -sf /usr/bin/python3.12 /usr/local/bin/python3

COPY requirements.txt .
RUN python3 -m pip install --upgrade pip setuptools wheel \
    && python3 -m pip install torch==2.10.0 torchvision --index-url https://download.pytorch.org/whl/cu128 \
    && python3 -m pip install -r requirements.txt \
    && python3 -m pip check

COPY smoke.py storage_smoke.py model_smoke.py inference_smoke.py geometry.py geometry_smoke.py sam31.py jobs.py .
RUN mkdir -p /tmp/home /tmp/xdg \
    && HOME=/tmp/home XDG_CACHE_HOME=/tmp/xdg python3 /app/smoke.py \
    && python3 /app/geometry_smoke.py \
    && rm -rf /tmp/home /tmp/xdg

COPY app.py .

EXPOSE 8000
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
