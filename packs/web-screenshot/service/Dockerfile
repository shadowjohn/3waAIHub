FROM mcr.microsoft.com/playwright:v1.61.1-jammy

WORKDIR /app
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY url_policy.js capture.js url_policy_cases.json test_url_policy.js test_capture.js ./
RUN cp capture.js capture && chmod 0755 capture

CMD ["/app/capture"]
