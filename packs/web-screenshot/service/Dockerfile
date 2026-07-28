FROM mcr.microsoft.com/playwright:v1.61.1-jammy

USER root
WORKDIR /app
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

RUN apt-get update \
    && apt-get install -y --no-install-recommends acl iptables \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --system capture \
    && useradd --system --gid capture --home-dir /app --shell /usr/sbin/nologin capture \
    && install -d -m 0700 /workspace/input /workspace/output \
    && touch /workspace/input/request.json \
    && chmod 0600 /workspace/input/request.json

COPY url_policy.js capture.js url_policy_cases.json test_url_policy.js test_capture.js capture-entrypoint.sh test_egress_firewall.sh egress_self_check.js ./
RUN cp capture.js capture \
    && chmod 0755 capture capture-entrypoint.sh test_egress_firewall.sh

ENTRYPOINT ["/app/capture-entrypoint.sh"]
CMD ["/app/capture"]
