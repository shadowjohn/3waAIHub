FROM python:3.13-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends acl iptables util-linux \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --system edge \
    && useradd --system --gid edge --home-dir /app --shell /usr/sbin/nologin edge

RUN python3 -m pip install edge-tts==7.2.6

COPY edge-tts-entrypoint.sh synthesize.py test_egress_firewall.sh test_synthesize.py ./
RUN chmod 0755 edge-tts-entrypoint.sh synthesize.py test_egress_firewall.sh test_synthesize.py \
    && python3 -m unittest -v test_synthesize.py

ENTRYPOINT ["/app/edge-tts-entrypoint.sh"]
CMD ["/app/synthesize.py"]
