FROM mcr.microsoft.com/playwright:v1.61.1-jammy AS base
USER root
WORKDIR /app
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
COPY requirements.txt ./
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-pip acl iptables \
    && rm -rf /var/lib/apt/lists/* \
    && python3 -m pip install --no-cache-dir -r requirements.txt \
    && groupadd --system crawler \
    && useradd --system --gid crawler --home-dir /app --shell /usr/sbin/nologin crawler
COPY crawler ./crawler
COPY crawl_runner.py crawl-entrypoint.sh ./
RUN sed -i 's/\r$//' crawl-entrypoint.sh \
    && chmod 0755 crawl-entrypoint.sh crawl_runner.py \
    && install -d -m 0700 /workspace/input /workspace/output \
    && touch /workspace/input/request.json \
    && chmod 0600 /workspace/input/request.json
ENTRYPOINT ["/app/crawl-entrypoint.sh"]
CMD ["python3", "/app/crawl_runner.py"]

FROM base AS test
COPY tests ./tests

FROM base AS runtime
