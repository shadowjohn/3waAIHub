#!/usr/bin/env python3
import argparse
import base64
import json
import sys
import urllib.error
import urllib.request
import wave
from pathlib import Path


def validate_wav(path: Path) -> tuple[bytes, dict[str, float | int | str]]:
    data = path.read_bytes()
    try:
        with wave.open(str(path), "rb") as wav:
            channels = wav.getnchannels()
            rate = wav.getframerate()
            frames = wav.getnframes()
            width = wav.getsampwidth()
    except wave.Error as exc:
        raise ValueError(f"invalid_wav:{exc}") from exc

    duration = frames / rate if rate else 0
    if channels != 1:
        raise ValueError("wav_must_be_mono")
    if rate != 16000:
        raise ValueError("wav_must_be_16khz")
    if duration <= 0 or duration > 30:
        raise ValueError("wav_duration_out_of_range")

    return data, {
        "channels": channels,
        "sample_rate": rate,
        "duration_sec": round(duration, 3),
        "sample_width": width,
        "size": len(data),
    }


def completions_url(base_url: str) -> str:
    base = base_url.rstrip("/")
    if base.endswith("/v1/chat/completions"):
        return base
    if base.endswith("/v1"):
        return base + "/chat/completions"
    return base + "/v1/chat/completions"


def main() -> int:
    parser = argparse.ArgumentParser(description="Smoke test Gemma4 vLLM audio input.")
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--model", required=True)
    parser.add_argument("--audio", required=True)
    args = parser.parse_args()

    try:
        audio_data, audio_meta = validate_wav(Path(args.audio))
    except (OSError, ValueError) as exc:
        print(json.dumps({"ok": False, "error": "bad_audio", "detail": str(exc)}, ensure_ascii=False))
        return 2

    payload = {
        "model": args.model,
        "messages": [{
            "role": "user",
            "content": [
                {"type": "text", "text": "請用正體中文簡短說明這段音訊內容。"},
                {
                    "type": "input_audio",
                    "input_audio": {
                        "data": base64.b64encode(audio_data).decode("ascii"),
                        "format": "wav",
                    },
                },
            ],
        }],
        "max_tokens": 256,
        "temperature": 0.1,
        "stream": False,
    }

    request = urllib.request.Request(
        completions_url(args.base_url),
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=600) as response:
            raw = response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")[:800]
        print(json.dumps({"ok": False, "error": "vllm_http_error", "status": exc.code, "detail": detail}, ensure_ascii=False))
        return 3
    except urllib.error.URLError as exc:
        print(json.dumps({"ok": False, "error": "vllm_unavailable", "detail": str(exc)}, ensure_ascii=False))
        return 3

    try:
        body = json.loads(raw)
        choices = body.get("choices") if isinstance(body, dict) else None
        message = choices[0].get("message", {}) if isinstance(choices, list) and choices else {}
        text = str(message.get("content") or "").strip()
        usage = body.get("usage") if isinstance(body.get("usage"), dict) else {}
    except Exception as exc:
        print(json.dumps({"ok": False, "error": "bad_vllm_json", "detail": str(exc)}, ensure_ascii=False))
        return 3

    if not text:
        print(json.dumps({"ok": False, "error": "empty_output", "audio": audio_meta}, ensure_ascii=False))
        return 4

    print(json.dumps({"ok": True, "text": text, "usage": usage, "audio": audio_meta}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
