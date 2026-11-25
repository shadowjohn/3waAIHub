FROM python:3.12-slim-bookworm

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    HF_HUB_OFFLINE=1 \
    TRANSFORMERS_OFFLINE=1 \
    HF_HUB_DISABLE_IMPLICIT_TOKEN=1

WORKDIR /app
RUN python -m pip install --index-url https://download.pytorch.org/whl/cu129 \
        torch==2.8.0+cu129 torchvision==0.23.0+cu129

COPY requirements.txt .
RUN python -m pip install -r requirements.txt && python -m pip check

COPY app.py engine.py model.py provision.py .
RUN useradd --uid 10001 --create-home worker
USER worker
EXPOSE 8000
CMD ["python", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
