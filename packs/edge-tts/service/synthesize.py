#!/usr/bin/env python3
from __future__ import annotations

import asyncio
import json
import re
import ssl
import time
from importlib.metadata import PackageNotFoundError, version
from pathlib import Path
from typing import Any

import edge_tts
import aiohttp
from edge_tts import exceptions as edge_exceptions


MAX_AUDIO_BYTES = 16 * 1024 * 1024
MAX_CAPTION_BYTES = 512 * 1024
TICKS_PER_MILLISECOND = 10000
ALLOWED_REQUEST = {"text", "voice", "rate", "volume", "pitch", "include_subtitles"}
LEGACY_REQUEST = ALLOWED_REQUEST - {"include_subtitles"}
SENTENCE_TERMINATORS = (".", "!", "?", "。", "！", "？")
VOICE_CATALOG_PATH = Path(__file__).with_name("voice_catalog.json")
VOICE_CATALOG_KEYS = {"id", "display_name", "locale", "gender", "memo", "demo_text", "demo_file"}
VOICE_ID_RE = re.compile(r"^zh-(?:TW|CN|HK)(?:-[a-z]+)?-[A-Za-z]+Neural$")
LOCALE_RE = re.compile(r"^zh-(?:TW|CN|HK)(?:-[a-z]+)?$")
DEMO_FILE_RE = re.compile(r"^[0-9]{2}_[a-z0-9_]+\.mp3$")
CONTROL_RE = re.compile(r"[\x00-\x1f\x7f]")
RATES = {"-50%", "-25%", "+0%", "+25%", "+50%"}
VOLUMES = RATES
PITCHES = {"-50Hz", "-25Hz", "+0Hz", "+25Hz", "+50Hz"}


class RunnerError(RuntimeError):
    def __init__(self, code: str):
        self.code = code
        super().__init__(code)


def fail(code: str) -> None:
    raise RunnerError(code)


def load_voice_catalog() -> list[dict[str, str]]:
    try:
        value = json.loads(VOICE_CATALOG_PATH.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        fail("edge_tts_failed")
    if not isinstance(value, list) or not value:
        fail("edge_tts_failed")
    ids: set[str] = set()
    files: set[str] = set()
    for profile in value:
        if not isinstance(profile, dict) or set(profile) != VOICE_CATALOG_KEYS or any(
            not isinstance(profile.get(key), str) or not profile[key] or CONTROL_RE.search(profile[key])
            for key in VOICE_CATALOG_KEYS
        ):
            fail("edge_tts_failed")
        voice_id = profile["id"]
        locale = profile["locale"]
        demo_file = profile["demo_file"]
        if (
            not VOICE_ID_RE.fullmatch(voice_id)
            or not LOCALE_RE.fullmatch(locale)
            or not voice_id.startswith(locale + "-")
            or not DEMO_FILE_RE.fullmatch(demo_file)
            or "/" in demo_file
            or "\\" in demo_file
            or ".." in demo_file
            or profile["gender"] not in {"male", "female"}
            or voice_id in ids
            or demo_file in files
        ):
            fail("edge_tts_failed")
        ids.add(voice_id)
        files.add(demo_file)
    return value


def voice_ids() -> set[str]:
    return {profile["id"] for profile in load_voice_catalog()}


def read_request(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        fail("edge_tts_failed")
    if not isinstance(value, dict):
        fail("edge_tts_failed")
    return value


def validate_request(value: dict[str, Any]) -> dict[str, Any]:
    if set(value) == LEGACY_REQUEST:
        include_subtitles = False
    elif set(value) == ALLOWED_REQUEST:
        include_subtitles = value.get("include_subtitles")
    else:
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
    catalogue_voice_ids = voice_ids()
    if (
        not isinstance(text, str)
        or not text
        or text_bytes > 4096
        or not isinstance(voice, str)
        or voice not in catalogue_voice_ids
        or not isinstance(rate, str)
        or rate not in RATES
        or not isinstance(volume, str)
        or volume not in VOLUMES
        or not isinstance(pitch, str)
        or pitch not in PITCHES
        or not isinstance(include_subtitles, bool)
    ):
        fail("edge_tts_failed")
    return {
        "text": text,
        "voice": voice,
        "rate": rate,
        "volume": volume,
        "pitch": pitch,
        "include_subtitles": include_subtitles,
    }


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


def write_text_artifact(path: Path, value: str) -> None:
    temporary = path.with_name("." + path.name + ".tmp")
    try:
        if path.is_symlink() or (path.exists() and not path.is_file()):
            fail("artifact_write_failed")
        remove_if_regular(temporary)
        encoded = value.encode("utf-8")
        if len(encoded) > MAX_CAPTION_BYTES:
            fail("artifact_write_failed")
        temporary.write_bytes(encoded)
        temporary.replace(path)
    except RunnerError:
        raise
    except (OSError, UnicodeEncodeError):
        fail("artifact_write_failed")


def boundary_entry(value: dict[str, Any]) -> dict[str, Any]:
    offset = value.get("offset")
    duration = value.get("duration")
    text = value.get("text")
    if (
        not isinstance(offset, int)
        or isinstance(offset, bool)
        or not isinstance(duration, int)
        or isinstance(duration, bool)
        or offset < 0
        or duration <= 0
        or not isinstance(text, str)
        or not text
    ):
        fail("edge_tts_failed")
    start_ms = offset // TICKS_PER_MILLISECOND
    end_ms = (offset + duration + TICKS_PER_MILLISECOND - 1) // TICKS_PER_MILLISECOND
    if end_ms <= start_ms:
        end_ms = start_ms + 1
    return {"text": text, "start_ms": start_ms, "end_ms": end_ms}


def append_boundary(entries: list[dict[str, Any]], value: dict[str, Any], previous_end_ticks: int | None) -> int:
    entry = boundary_entry(value)
    offset = value["offset"]
    end_ticks = offset + value["duration"]
    if previous_end_ticks is not None and offset < previous_end_ticks:
        fail("edge_tts_failed")
    entries.append(entry)
    return end_ticks


def sentences_from_words(words: list[dict[str, Any]]) -> list[dict[str, Any]]:
    sentences: list[dict[str, Any]] = []
    sentence_words: list[dict[str, Any]] = []
    for word in words:
        sentence_words.append(word)
        if word["text"].rstrip().endswith(SENTENCE_TERMINATORS):
            sentences.append({
                "text": " ".join(item["text"] for item in sentence_words),
                "start_ms": sentence_words[0]["start_ms"],
                "end_ms": word["end_ms"],
            })
            sentence_words = []
    if sentence_words:
        sentences.append({
            "text": " ".join(item["text"] for item in sentence_words),
            "start_ms": sentence_words[0]["start_ms"],
            "end_ms": sentence_words[-1]["end_ms"],
        })
    return sentences


def timestamp(milliseconds: int, separator: str) -> str:
    hours, remaining = divmod(milliseconds, 3_600_000)
    minutes, remaining = divmod(remaining, 60_000)
    seconds, milliseconds = divmod(remaining, 1_000)
    return f"{hours:02}:{minutes:02}:{seconds:02}{separator}{milliseconds:03}"


def render_vtt(sentences: list[dict[str, Any]]) -> str:
    return "WEBVTT\n\n" + "\n\n".join(
        f"{timestamp(entry['start_ms'], '.')} --> {timestamp(entry['end_ms'], '.')}\n{entry['text']}"
        for entry in sentences
    ) + "\n"


def render_srt(sentences: list[dict[str, Any]]) -> str:
    return "\n\n".join(
        f"{index}\n{timestamp(entry['start_ms'], ',')} --> {timestamp(entry['end_ms'], ',')}\n{entry['text']}"
        for index, entry in enumerate(sentences, start=1)
    ) + "\n"


async def stream_captioned_audio_async(request: dict[str, Any], temporary_audio: Path) -> tuple[int, list[dict[str, Any]], list[dict[str, Any]]]:
    words: list[dict[str, Any]] = []
    word_end_ticks: int | None = None
    audio_bytes = 0
    try:
        with temporary_audio.open("wb") as audio_file:
            async for event in edge_tts.Communicate(
                request["text"],
                request["voice"],
                rate=request["rate"],
                volume=request["volume"],
                pitch=request["pitch"],
                boundary="WordBoundary",
            ).stream():
                if not isinstance(event, dict):
                    fail("edge_tts_failed")
                event_type = event.get("type")
                if event_type == "audio":
                    data = event.get("data")
                    if not isinstance(data, bytes):
                        fail("edge_tts_failed")
                    audio_bytes += len(data)
                    if audio_bytes > MAX_AUDIO_BYTES:
                        fail("artifact_write_failed")
                    audio_file.write(data)
                elif event_type == "WordBoundary":
                    word_end_ticks = append_boundary(words, event, word_end_ticks)
    except (asyncio.TimeoutError, TimeoutError):
        fail("edge_tts_timeout")
    except (aiohttp.ClientError, ConnectionError, ssl.SSLError, edge_exceptions.NoAudioReceived, edge_exceptions.WebSocketError):
        fail("upstream_unavailable")
    except RunnerError:
        raise
    except OSError:
        fail("artifact_write_failed")
    except Exception:
        fail("edge_tts_failed")
    if not words:
        fail("edge_tts_failed")
    return audio_bytes, sentences_from_words(words), words


def stream_captioned_audio(request: dict[str, Any], temporary_audio: Path) -> tuple[int, list[dict[str, Any]], list[dict[str, Any]]]:
    return asyncio.run(stream_captioned_audio_async(request, temporary_audio))


def remove_job_artifacts(paths: tuple[Path, ...]) -> None:
    for path in paths:
        remove_if_regular(path)


def run_job(request_path: Path, output_dir: Path) -> None:
    if not output_dir.is_dir() or output_dir.is_symlink():
        fail("artifact_write_failed")
    audio_path = output_dir / "generated_audio.mp3"
    temporary_audio = output_dir / ".generated_audio.mp3.tmp"
    metadata_path = output_dir / "synthesis_metadata.json"
    vtt_path = output_dir / "subtitle.vtt"
    srt_path = output_dir / "subtitle.srt"
    timeline_path = output_dir / "speech_timeline.json"
    artifacts = (
        audio_path,
        temporary_audio,
        metadata_path,
        metadata_path.with_name("." + metadata_path.name + ".tmp"),
        vtt_path,
        vtt_path.with_name("." + vtt_path.name + ".tmp"),
        srt_path,
        srt_path.with_name("." + srt_path.name + ".tmp"),
        timeline_path,
        timeline_path.with_name("." + timeline_path.name + ".tmp"),
    )
    try:
        request = validate_request(read_request(request_path))
    except RunnerError:
        remove_job_artifacts(artifacts)
        raise
    remove_job_artifacts(artifacts)
    started = time.monotonic()
    try:
        if request["include_subtitles"]:
            audio_bytes, sentences, words = stream_captioned_audio(request, temporary_audio)
        else:
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
        if request["include_subtitles"]:
            duration_ms = max(entry["end_ms"] for entry in words)
            write_text_artifact(vtt_path, render_vtt(sentences))
            write_text_artifact(srt_path, render_srt(sentences))
            write_text_artifact(timeline_path, json.dumps({
                "version": 1,
                "unit": "ms",
                "duration_ms": duration_ms,
                "sentences": sentences,
                "words": words,
            }, ensure_ascii=True, separators=(",", ":")) + "\n")
    except RunnerError:
        remove_job_artifacts(artifacts)
        raise
    except OSError:
        remove_job_artifacts(artifacts)
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
