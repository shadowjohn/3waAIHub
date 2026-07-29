#!/usr/bin/env python3
from __future__ import annotations

import asyncio
import json
import ssl
import time
from importlib.metadata import PackageNotFoundError, version
from pathlib import Path
from typing import Any

import edge_tts
import aiohttp
from edge_tts import exceptions as edge_exceptions


MAX_AUDIO_BYTES = 16 * 1024 * 1024
ALLOWED_REQUEST = {"text", "voice", "rate", "volume", "pitch"}
VOICES = {
    "zh-TW-HsiaoChenNeural",
    "zh-TW-HsiaoYuNeural",
    "zh-TW-YunJheNeural",
    "en-US-EmmaMultilingualNeural",
    "en-US-AndrewMultilingualNeural",
}
RATES = {"-50%", "-25%", "+0%", "+25%", "+50%"}
VOLUMES = RATES
PITCHES = {"-50Hz", "-25Hz", "+0Hz", "+25Hz", "+50Hz"}


class RunnerError(RuntimeError):
    def __init__(self, code: str):
        self.code = code
        super().__init__(code)


def fail(code: str) -> None:
    raise RunnerError(code)


def read_request(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        fail("edge_tts_failed")
    if not isinstance(value, dict):
        fail("edge_tts_failed")
    return value


def validate_request(value: dict[str, Any]) -> dict[str, str]:
    if set(value) != ALLOWED_REQUEST:
        fail("edge_tts_failed")
    text = value.get("text")
    voice = value.get("voice")
    rate = value.get("rate")
    volume = value.get("volume")
    pitch = value.get("pitch")
    try:
        text_bytes = len(text.encode("utf-8")) if isinstance(text, str) else 0
    except UnicodeEncodeError:
        text_bytes = 0
    if (
        not isinstance(text, str)
        or not text
        or text_bytes > 4096
        or voice not in VOICES
        or rate not in RATES
        or volume not in VOLUMES
        or pitch not in PITCHES
    ):
        fail("edge_tts_failed")
    return {"text": text, "voice": voice, "rate": rate, "volume": volume, "pitch": pitch}


def client_version() -> str:
    try:
        return version("edge-tts")
    except PackageNotFoundError:
        return "unknown"


def remove_if_regular(path: Path) -> None:
    if path.is_symlink() or (path.exists() and not path.is_file()):
        fail("artifact_write_failed")
    if path.exists():
        try:
            path.unlink()
        except OSError:
            fail("artifact_write_failed")


def write_metadata(path: Path, value: dict[str, Any]) -> None:
    temporary = path.with_name("." + path.name + ".tmp")
    try:
        remove_if_regular(temporary)
        encoded = json.dumps(value, ensure_ascii=True, separators=(",", ":")) + "\n"
        if len(encoded.encode("utf-8")) > 65536:
            fail("artifact_write_failed")
        temporary.write_text(encoded, encoding="utf-8")
        temporary.replace(path)
    except RunnerError:
        raise
    except OSError:
        fail("artifact_write_failed")


def run_job(request_path: Path, output_dir: Path) -> None:
    request = validate_request(read_request(request_path))
    if not output_dir.is_dir() or output_dir.is_symlink():
        fail("artifact_write_failed")
    audio_path = output_dir / "generated_audio.mp3"
    temporary_audio = output_dir / ".generated_audio.mp3.tmp"
    metadata_path = output_dir / "synthesis_metadata.json"
    remove_if_regular(audio_path)
    remove_if_regular(temporary_audio)
    remove_if_regular(metadata_path)
    started = time.monotonic()
    try:
        edge_tts.Communicate(
            request["text"],
            request["voice"],
            rate=request["rate"],
            volume=request["volume"],
            pitch=request["pitch"],
        ).save_sync(str(temporary_audio))
    except (asyncio.TimeoutError, TimeoutError):
        fail("edge_tts_timeout")
    except (aiohttp.ClientError, ConnectionError, OSError, ssl.SSLError, edge_exceptions.NoAudioReceived, edge_exceptions.WebSocketError):
        fail("upstream_unavailable")
    except Exception:
        fail("edge_tts_failed")
    try:
        if temporary_audio.is_symlink() or not temporary_audio.is_file():
            fail("artifact_write_failed")
        audio_bytes = temporary_audio.stat().st_size
        if not 0 < audio_bytes <= MAX_AUDIO_BYTES:
            fail("artifact_write_failed")
        temporary_audio.replace(audio_path)
        write_metadata(metadata_path, {
            "provider": "Microsoft Edge TTS",
            "client_version": client_version(),
            "voice": request["voice"],
            "rate": request["rate"],
            "volume": request["volume"],
            "pitch": request["pitch"],
            "format": "mp3",
            "audio_bytes": audio_bytes,
            "elapsed_seconds": max(0.0, time.monotonic() - started),
            "warnings": [],
        })
    except RunnerError:
        raise
    except OSError:
        fail("artifact_write_failed")


def main() -> int:
    try:
        run_job(Path("/workspace/input/request.json"), Path("/workspace/output"))
    except RunnerError as error:
        print(f"AIHUB_ERROR_CODE={error.code}", file=__import__("sys").stderr)
        return 1
    except Exception:
        print("AIHUB_ERROR_CODE=edge_tts_failed", file=__import__("sys").stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
