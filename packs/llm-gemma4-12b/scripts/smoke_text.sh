#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:18110/chat}"
curl -sS "$BASE_URL" \
  -H "Content-Type: application/json" \
  -d '{"text":"請用一句正體中文介紹 3waAIHub。","real_inference":1,"enable_thinking":false,"max_tokens":128}'
