# Gemma 4 12B Unified LLM Service

3waAIHub sync API Pack for `google/gemma-4-12B-it-qat-w4a16-ct`.

- Pack ID: `llm-gemma4-12b`
- Default service key: `gemma4-main`
- Default mode: `chat`
- Public boundaries: `api.php?mode=chat`, `api.php?mode=photo`, `api.php?mode=audio`
- Hub adapter endpoints: `POST /chat`, `POST /photo`, `POST /audio`
- Internal runtime: vLLM sidecar on the Docker network

Use `vllm/vllm-openai:latest` for Gemma 4 QAT W4A16. The older
`vllm/vllm-openai:gemma4` tag does not include the runtime fixes needed
for the `gemma4_unified` architecture and compressed-tensors path.

PhaseL-1A kept the Hub boundary small: text-only, non-streaming JSON, and Q4 real inference through a thin adapter. PhaseL-1C added `image_id` based Photo Vision. PhaseL-1D added short WAV one-shot audio input smoke. PhaseL-1E adds short-lived `audio_id` reuse.

Default single-user runtime tuning:

- `VLLM_GPU_MEMORY_UTILIZATION=0.64`
- `VLLM_MAX_MODEL_LEN=16384`
- `VLLM_MAX_NUM_SEQS=1`

This is a single-user development default that leaves more headroom for
long-running vision services than the earlier `0.72` setting. Running Gemma 4,
SAM3, and TranslateGemma/Ollama as GPU-resident services at the same time still
requires explicit GPU residency scheduling.

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

Audio input:

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=audio_upload" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "audio=@sample.wav"

curl -X POST "http://localhost/3waAIHub/api.php?mode=audio" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "audio_id=aud_..." \
  -F "operation=understand" \
  -F "text=這段錄音的重點是什麼？" \
  -F "max_tokens=512" \
  -F "real_inference=1"
```

Audio limits:

- WAV only
- 16kHz mono
- <= 30 seconds
- <= 16MB
- `audio_id` TTL is 7 days
- `audio_id` is a managed upload asset, not a conversation session

Deferred on purpose:

- SSE streaming passthrough
- long audio / timestamps / diarization / VAD
- tool calling
- structured output mode
- OpenAI-compatible Gateway surface

原則：模型適配 Hub，不讓 Hub Core 反過來遷就模型。
