# Gemma 4 12B Unified LLM Service

3waAIHub sync API Pack for `google/gemma-4-12B-it-qat-w4a16-ct`.

- Pack ID: `llm-gemma4-12b`
- Default service key: `gemma4-main`
- Default mode: `chat`
- Public boundary: `api.php?mode=chat`
- Hub adapter endpoint: `POST /chat`
- Internal runtime: vLLM sidecar on the Docker network

Use `vllm/vllm-openai:latest` for Gemma 4 QAT W4A16. The older
`vllm/vllm-openai:gemma4` tag does not include the runtime fixes needed
for the `gemma4_unified` architecture and compressed-tensors path.

PhaseL-1A keeps the Hub boundary small: text-only, non-streaming JSON, and Q4 real inference through a thin adapter. The adapter converts Hub `/chat` requests to vLLM `/v1/chat/completions` internally.

Example Hub payload:

```json
{
  "text": "請用正體中文解釋 RAG 中 embedding 與 reranking 的差異。",
  "system_prompt": "你是 3waAIHub 本地 AI 助手。",
  "temperature": 0.2,
  "max_tokens": 512,
  "enable_thinking": false,
  "real_inference": true
}
```

Deferred on purpose:

- SSE streaming passthrough
- Image / multimodal input
- tool calling
- structured output mode
- OpenAI-compatible Gateway surface

原則：模型適配 Hub，不讓 Hub Core 反過來遷就模型。
