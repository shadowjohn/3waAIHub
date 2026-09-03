FROM nvidia/cuda:12.8.1-devel-ubuntu22.04 AS cuda_devel

FROM nvidia/cuda:12.8.1-cudnn-runtime-ubuntu22.04

# PyTorch's cuDNN path needs NVRTC at inference time; the runtime base omits it.
COPY --from=cuda_devel /usr/local/cuda/lib64/libnvrtc.so* /usr/local/cuda/lib64/
COPY --from=cuda_devel /usr/local/cuda/lib64/libnvrtc-builtins.so* /usr/local/cuda/lib64/

ARG BREEZYVOICE_UPSTREAM_REVISION=d592c9d3e8927a0f53f68616387060dcd32a05ea

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    LD_LIBRARY_PATH=/usr/local/cuda/lib64:/usr/local/lib/python3.10/dist-packages/torch/lib

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates git python3 python3-pip libsndfile1 ffmpeg \
    && python3 -c 'import sys; assert sys.version_info[:2] == (3, 10)' \
    && rm -rf /var/lib/apt/lists/*

RUN set -eu; \
    case "$BREEZYVOICE_UPSTREAM_REVISION" in ''|main|latest|*/*) exit 64 ;; esac; \
    printf '%s' "$BREEZYVOICE_UPSTREAM_REVISION" | grep -Eq '^[0-9a-f]{40}$'; \
    git clone https://github.com/mtkresearch/BreezyVoice.git /opt/breezyvoice; \
    git -C /opt/breezyvoice checkout --detach "$BREEZYVOICE_UPSTREAM_REVISION"; \
    test "$(git -C /opt/breezyvoice rev-parse HEAD)" = "$BREEZYVOICE_UPSTREAM_REVISION"

RUN python3 -m pip install --no-cache-dir --upgrade pip \
    && python3 -m pip install --no-cache-dir --index-url https://download.pytorch.org/whl/cu128 torch==2.8.0 torchaudio==2.8.0

COPY service/requirements.txt ./requirements.txt
RUN python3 -m pip install --no-cache-dir --no-build-isolation -r requirements.txt \
    && python3 -m pip check \
    && python3 -m pip install --no-cache-dir --force-reinstall --no-deps onnxruntime-gpu==1.22.0 \
    && python3 -c 'import onnxruntime as ort; assert "CUDAExecutionProvider" in ort.get_available_providers()' \
    && python3 -m pip install --no-cache-dir --no-deps --no-build-isolation openai-whisper==20231117 \
    && python3 -c 'import whisper; assert callable(whisper.log_mel_spectrogram)'

RUN python3 - <<'PY'
from pathlib import Path

path = Path('/usr/local/lib/python3.10/dist-packages/g2pw/api.py')
source = path.read_text(encoding='utf-8')
before = "onnxruntime.InferenceSession(os.path.join(model_dir, 'g2pw.onnx'), sess_options=sess_options)"
after = "onnxruntime.InferenceSession(os.path.join(model_dir, 'g2pw.onnx'), sess_options=sess_options, providers=['CUDAExecutionProvider', 'CPUExecutionProvider'])"
if source.count(before) != 1:
    raise RuntimeError('g2pw ONNX Runtime compatibility patch target is unavailable')
path.write_text(source.replace(before, after), encoding='utf-8')
PY

ENV PYTHONPATH=/opt/breezyvoice:/opt/breezyvoice/third_party/Matcha-TTS \
    HF_HOME=/opt/huggingface \
    HOME=/tmp/home \
    XDG_CACHE_HOME=/tmp/xdg \
    NUMBA_CACHE_DIR=/tmp/numba \
    MPLCONFIGDIR=/tmp/matplotlib

RUN mkdir -p /tmp/home /tmp/xdg /tmp/numba /tmp/matplotlib \
    && chmod 1777 /tmp/home /tmp/xdg /tmp/numba /tmp/matplotlib \
    && python3 - <<'PY'
from g2pw.api import download_model
from transformers import BertTokenizer

download_model('/opt/breezyvoice/G2PWModel')
BertTokenizer.from_pretrained('bert-base-chinese')
PY

RUN chmod -R a+rwX /opt/huggingface

ENV HF_HUB_OFFLINE=1 \
    TRANSFORMERS_OFFLINE=1

COPY service/app.py service/job.py service/provision.py ./
COPY jobs/voice_generate.sh jobs/provision_models.sh ./
RUN python3 -m py_compile /app/app.py /app/job.py /app/provision.py \
    && chmod 0755 /app/job.py /app/provision.py /app/voice_generate.sh /app/provision_models.sh \
    && ln -s /app/voice_generate.sh /app/breezyvoice-synthesize \
    && ln -s /app/provision_models.sh /app/breezyvoice-provision-models

CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
