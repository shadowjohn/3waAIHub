FROM nvidia/cuda:12.9.0-cudnn-runtime-ubuntu22.04

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libglib2.0-0 \
        libgl1 \
        python3 \
        python3-pip \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN python3 -m pip install --upgrade pip setuptools wheel \
    && python3 -m pip install -r requirements.txt \
    && python3 -m pip check

COPY smoke.py storage_smoke.py model_smoke.py inference_smoke.py .
RUN mkdir -p /tmp/ultralytics /tmp/xdg /tmp/home \
    && HOME=/tmp/home \
       XDG_CACHE_HOME=/tmp/xdg \
       YOLO_CONFIG_DIR=/tmp/ultralytics \
       ULTRALYTICS_SETTINGS_DIR=/tmp/ultralytics \
       python3 /app/smoke.py \
    && rm -rf /tmp/ultralytics /tmp/xdg /tmp/home

COPY app.py .

EXPOSE 8000
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
