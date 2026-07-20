ARG VLLM_IMAGE=vllm/vllm-openai:latest
FROM ${VLLM_IMAGE}

RUN set -eux; \
    version="$(python3 -c 'import vllm; print(vllm.__version__)')"; \
    if command -v uv >/dev/null 2>&1; then \
        uv pip install --system "vllm[audio]==${version}"; \
    else \
        python3 -m pip install --no-cache-dir "vllm[audio]==${version}"; \
    fi
