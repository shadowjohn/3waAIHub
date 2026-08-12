FROM nvidia/cuda:12.9.0-cudnn-runtime-ubuntu22.04

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app
RUN apt-get update \
    && apt-get install -y --no-install-recommends git libgl1 libglib2.0-0 python3 python3-pip \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN python3 -m pip install --extra-index-url https://download.pytorch.org/whl/cu128 torch==2.9.1+cu128 torchvision==0.24.1+cu128 \
    && python3 -m pip install --no-build-isolation -r requirements.txt \
    && python3 -m pip check \
    && python3 -c 'import cv2'
RUN python3 -c "from importlib.util import find_spec; from pathlib import Path; spec = find_spec('basicsr'); assert spec and spec.submodule_search_locations; path = Path(next(iter(spec.submodule_search_locations))) / 'data' / 'degradations.py'; old = 'from torchvision.transforms.functional_tensor import rgb_to_grayscale'; new = 'from torchvision.transforms.functional import rgb_to_grayscale'; source = path.read_text(encoding='utf-8'); assert source.count(old) == 1, 'unexpected BasicSR rgb_to_grayscale import'; path.write_text(source.replace(old, new), encoding='utf-8')" \
    && python3 -c 'import cv2; import realesrgan'

COPY Dockerfile image_contract.py model_runtime.py provision_offline_assets.py storage_smoke.py model_smoke.py inference_smoke.py upscale_runner.py jobs.py app.py test_image_contract.py test_model_runtime.py test_model_smoke.py test_upscale_runner.py test_jobs.py test_app.py ./
RUN python3 -m unittest -v test_image_contract.py test_model_runtime.py test_model_smoke.py test_upscale_runner.py test_jobs.py test_app.py \
    && chmod 0555 /app/*.py

USER 65532:65532
EXPOSE 8000
CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
