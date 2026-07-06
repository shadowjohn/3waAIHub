#!/usr/bin/env bash
set -euo pipefail

ollama serve >/tmp/ollama.log 2>&1 &

for _ in $(seq 1 60); do
  if python3 -c 'import os, urllib.request; urllib.request.urlopen("http://" + os.getenv("OLLAMA_HOST", "127.0.0.1:11434") + "/api/tags", timeout=1)' >/dev/null 2>&1; then
    if [ "${OLLAMA_AUTO_PULL:-0}" = "1" ] && [ -n "${OLLAMA_MODEL:-}" ]; then
      (ollama pull "${OLLAMA_MODEL}" >/tmp/ollama-pull.log 2>&1 || cat /tmp/ollama-pull.log >&2) &
    fi
    exec python3 -m uvicorn app:app --host 0.0.0.0 --port 8000
  fi
  sleep 1
done

cat /tmp/ollama.log >&2 || true
exit 1
