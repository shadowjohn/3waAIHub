FROM python:3.11-slim

ARG GPT_SOVITS_REV=d523079fc05d9a8028d6085bffe4a2757c32abb6

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential cmake ffmpeg git libopencc-dev libsndfile1 \
    && rm -rf /var/lib/apt/lists/*

RUN git clone --filter=blob:none https://github.com/RVC-Boss/GPT-SoVITS.git /opt/gpt-sovits \
    && git -C /opt/gpt-sovits checkout "${GPT_SOVITS_REV}" \
    && rm -rf /opt/gpt-sovits/.git

WORKDIR /app

COPY service/requirements.txt .
RUN python3 -m pip install --upgrade pip setuptools wheel \
    && python3 -m pip install --index-url https://download.pytorch.org/whl/cu128 torch==2.8.0 torchaudio==2.8.0 \
    && python3 -m pip install -r /opt/gpt-sovits/requirements.txt \
    && python3 -m pip install -r requirements.txt \
    && python3 -m pip check

COPY service/job.py service/app.py service/provision_offline_assets.py service/test_job.py service/test_app.py ./
COPY jobs/voice_generate.sh ./voice_generate.sh
RUN python3 -m unittest -v test_job.py test_app.py \
    && chmod 0755 /app/voice_generate.sh /app/provision_offline_assets.py \
    && mkdir -p /app/GPT_SoVITS/text \
    && ln -s /app/voice_generate.sh /app/voice-generate \
    && ln -s /models/gpt_sovits/g2pw /app/GPT_SoVITS/text/G2PWModel \
    && ln -s /app/provision_offline_assets.py /app/provision-offline-assets

ENV HF_HUB_OFFLINE=1 \
    HF_DATASETS_OFFLINE=1 \
    TRANSFORMERS_OFFLINE=1 \
    PIP_NO_INDEX=1 \
    PYTHONPATH=/opt/gpt-sovits:/opt/gpt-sovits/GPT_SoVITS:/opt/gpt-sovits/GPT_SoVITS/eres2net

EXPOSE 8000
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
