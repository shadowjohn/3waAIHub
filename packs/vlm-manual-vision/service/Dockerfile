FROM nvidia/cuda:12.9.0-cudnn-runtime-ubuntu22.04

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    HF_HUB_OFFLINE=1 \
    TRANSFORMERS_OFFLINE=1

WORKDIR /app
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-pip util-linux \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN python3 -m pip install --upgrade pip setuptools wheel \
    && python3 -m pip install -r requirements.txt \
    && python3 -m pip check

COPY Dockerfile Dockerfile
COPY app.py entrypoint.sh provision.py acceptance.py gpu_smoke.py .
COPY tests/test_app.py tests/test_app.py
COPY tests/test_dockerfile.py tests/test_dockerfile.py
COPY tests/test_endpoint.py tests/test_endpoint.py
COPY tests/test_entrypoint.py tests/test_entrypoint.py
RUN useradd --create-home --uid 10001 appuser \
    && chmod 0755 /app/entrypoint.sh \
    && mkdir -p /cache/manual-vision /data/service \
    && mkdir -p /models/manual-vision \
    && chmod 0555 /models/manual-vision \
    && python3 -m unittest discover -v tests \
    && /app/entrypoint.sh /bin/sh -ec 'test "$(id -u)" = 10001; test "$(id -g)" = 10001; test -w /cache/manual-vision; test -w /data/service; ! test -w /models/manual-vision'

EXPOSE 8000
ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
