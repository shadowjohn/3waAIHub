FROM python:3.11-slim

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libgomp1 \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN python3 -m pip install --upgrade pip setuptools wheel \
    && python3 -m pip install -r requirements.txt \
    && python3 -m pip check \
    && python3 -c 'from pathlib import Path; import site; print("\n".join(str(Path(root) / package) for root in site.getsitepackages() for package in ("nvidia/cublas/lib", "nvidia/cudnn/lib") if (Path(root) / package).is_dir()))' > /etc/ld.so.conf.d/ct2-nvidia.conf \
    && test -s /etc/ld.so.conf.d/ct2-nvidia.conf \
    && ldconfig

COPY app.py smoke.py storage_smoke.py test_app.py .
RUN mkdir -p /tmp/home /tmp/xdg \
    && HOME=/tmp/home \
       XDG_CACHE_HOME=/tmp/xdg \
       python3 /app/smoke.py \
    && rm -rf /tmp/home /tmp/xdg

RUN python3 -m unittest -v test_app.py

EXPOSE 8000
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
