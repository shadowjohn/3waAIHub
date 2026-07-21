from __future__ import annotations

import hashlib
import json
import math
import re
import struct
import wave
from pathlib import Path
from typing import Any

BOUNDARY_ACTIONS = {"direct_concat", "silence_insert", "crossfade", "trim_then_pause", "regenerate_chunk"}
_OPEN = "([{（［｛「『“‘"
_CLOSE = ")] }）］｝」』”’".replace(" ", "")
_SENTENCE = "。！？!?；;"
_SOFT = "，,:："


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def normalize_semantic_v1(text: str) -> str:
    value = re.sub(r"\s+", " ", text.strip())
    if not value:
        raise RuntimeError("text_invalid")
    return value


def _protected_break(text: str, index: int) -> bool:
    left = text[:index]
    right = text[index:]
    if re.search(r"(?:\d[\d,]*|\d+\.\d+)$", left) and re.match(r"(?:\d|\s*(?:rpm|mm|cm|ms|Hz|N·m)\b)", right, re.I):
        return True
    token = re.search(r"[A-Za-z0-9.-]+$", left)
    if token and re.match(r"[A-Za-z0-9.-]", right or ""):
        return True
    if re.search(r"(?:\b(?:Mr|Mrs|Ms|Dr|Prof|Sr|Jr|vs|etc|e\.g|i\.e)|(?:[A-Za-z]\.){1,})\.$", left, re.I):
        return True
    return False


def _boundary_positions(text: str) -> list[int]:
    depth = 0
    positions: list[int] = []
    for offset, char in enumerate(text):
        if char in _OPEN:
            depth += 1
            continue
        if char in _CLOSE:
            depth = max(0, depth - 1)
            continue
        if depth == 0 and char in _SENTENCE + _SOFT and not _protected_break(text, offset + 1):
            positions.append(offset + 1)
    return positions


def _hard_cut(text: str, start: int, limit: int) -> int:
    end = min(len(text), start + limit)
    for offset in range(end, start, -1):
        if text[offset - 1].isspace() and not _protected_break(text, offset):
            return offset
    for offset in range(end, start, -1):
        if not _protected_break(text, offset):
            return offset
    raise RuntimeError("chunk_boundary_invalid")


def split_semantic_v1(normalized: str, max_chars: int) -> list[str]:
    if not isinstance(max_chars, int) or max_chars < 42 or max_chars > 4096:
        raise RuntimeError("chunk_limit_invalid")
    positions = _boundary_positions(normalized)
    chunks: list[str] = []
    start = 0
    while start < len(normalized):
        end = min(len(normalized), start + max_chars)
        if end == len(normalized):
            chunks.append(normalized[start:end])
            break
        candidates = [position for position in positions if start < position <= end]
        cut = candidates[-1] if candidates else _hard_cut(normalized, start, max_chars)
        if cut <= start:
            raise RuntimeError("chunk_boundary_invalid")
        chunks.append(normalized[start:cut])
        start = cut
    if not chunks or any(not chunk for chunk in chunks) or "".join(chunks) != normalized:
        raise RuntimeError("chunk_plan_invalid")
    return chunks


def seed_for_chunk(task_seed: int, chunk_id: str, policy: str) -> tuple[int, str]:
    if policy == "fixed":
        return task_seed, sha256_text(str(task_seed))
    if policy != "derived_per_chunk":
        raise RuntimeError("seed_policy_invalid")
    digest = sha256_text(str(task_seed) + chunk_id)
    return int(digest[:16], 16) % 2_147_483_648, digest


def make_plan(text: str, task_seed: int, seed_policy: str, max_chars: int) -> dict[str, Any]:
    normalized = normalize_semantic_v1(text)
    chunks = []
    for index, chunk_text in enumerate(split_semantic_v1(normalized, max_chars), 1):
        chunk_id = f"chunk-{index:04d}"
        seed, seed_hash = seed_for_chunk(task_seed, chunk_id, seed_policy)
        chunks.append({
            "id": chunk_id,
            "text": chunk_text,
            "text_sha256": sha256_text(chunk_text),
            "seed": seed,
            "seed_sha256": seed_hash,
        })
    core = {
        "normalization": "semantic-v1",
        "normalized_input": normalized,
        "max_chunk_chars": max_chars,
        "task_seed": task_seed,
        "seed_policy": seed_policy,
        "chunks": chunks,
    }
    return core | {"plan_sha256": sha256_text(canonical_json(core))}


def read_pcm(path: Path) -> tuple[int, list[int]]:
    with wave.open(str(path), "rb") as handle:
        if handle.getnchannels() != 1 or handle.getsampwidth() != 2 or handle.getcomptype() != "NONE":
            raise RuntimeError("checkpoint_audio_invalid")
        sample_rate = handle.getframerate()
        raw = handle.readframes(handle.getnframes())
    if sample_rate <= 0 or len(raw) % 2:
        raise RuntimeError("checkpoint_audio_invalid")
    return sample_rate, list(struct.unpack("<" + "h" * (len(raw) // 2), raw))


def write_pcm(path: Path, sample_rate: int, samples: list[int]) -> None:
    temporary = path.with_name(path.name + ".tmp")
    with wave.open(str(temporary), "wb") as handle:
        handle.setnchannels(1)
        handle.setsampwidth(2)
        handle.setframerate(sample_rate)
        handle.writeframes(struct.pack("<" + "h" * len(samples), *samples))
    temporary.replace(path)


def fake_synthesize(text: str, seed: int, voice_sha256: str, sample_rate: int) -> list[int]:
    frames = max(sample_rate // 4, min(sample_rate * 8, sample_rate * (280 + len(text) * 18) // 1000))
    base = 150 + ((seed + int(voice_sha256[:8], 16)) % 140)
    samples = []
    for frame in range(frames):
        envelope = min(1.0, frame / max(1, sample_rate // 80), (frames - frame) / max(1, sample_rate // 80))
        waveform = math.sin(2 * math.pi * (base + 14 * math.sin(frame / sample_rate * 2.1)) * frame / sample_rate)
        samples.append(int(11500 * envelope * waveform))
    return samples


def peak_guard(samples: list[int]) -> tuple[list[int], float]:
    peak = max((abs(sample) for sample in samples), default=0)
    limit = int(0.95 * 32767)
    if peak <= limit or peak == 0:
        return samples, 1.0
    gain = limit / peak
    return [int(round(sample * gain)) for sample in samples], gain


def boundary_for(left: str, sample_rate: int) -> dict[str, Any]:
    terminal = left.rstrip()[-1:] if left.rstrip() else ""
    if terminal in _SENTENCE:
        pause_ms = min(420, max(90, 90 + (ord(terminal) % 4) * 70))
        return {"action": "silence_insert", "pause_frames": sample_rate * pause_ms // 1000, "trim_frames": 0, "crossfade_frames": 0}
    if terminal in _SOFT:
        pause_ms = 80
        return {"action": "trim_then_pause", "pause_frames": sample_rate * pause_ms // 1000, "trim_frames": min(sample_rate // 200, sample_rate // 100), "crossfade_frames": 0}
    if terminal in _CLOSE:
        return {"action": "direct_concat", "pause_frames": 0, "trim_frames": 0, "crossfade_frames": 0}
    crossfade_ms = 20 + (ord(terminal or "a") % 3) * 10
    return {"action": "crossfade", "pause_frames": 0, "trim_frames": 0, "crossfade_frames": sample_rate * crossfade_ms // 1000}


def assemble(chunks: list[dict[str, Any]], sample_rate: int) -> tuple[list[int], list[dict[str, Any]]]:
    final: list[int] = []
    timeline: list[dict[str, Any]] = []
    for index, chunk in enumerate(chunks):
        samples = list(chunk["samples"])
        boundary = {"action": "direct_concat", "pause_frames": 0, "trim_frames": 0, "crossfade_frames": 0} if index == 0 else boundary_for(chunks[index - 1]["text"], sample_rate)
        if boundary["action"] == "trim_then_pause" and final:
            trim = min(boundary["trim_frames"], len(final))
            del final[-trim:]
        if boundary["action"] == "crossfade" and final:
            count = min(boundary["crossfade_frames"], len(final), len(samples))
            if count >= sample_rate // 100:
                start = len(final) - count
                for offset in range(count):
                    final[start + offset] = int(round(final[start + offset] * (count - offset) / count + samples[offset] * offset / count))
                samples = samples[count:]
            else:
                boundary = {"action": "direct_concat", "pause_frames": 0, "trim_frames": 0, "crossfade_frames": 0}
        if boundary["pause_frames"]:
            final.extend([0] * boundary["pause_frames"])
        start_frame = len(final)
        final.extend(samples)
        chunk["boundary"] = boundary
        timeline.append({"chunk_id": chunk["id"], "start_frame": start_frame, "end_frame": len(final), "sample_rate": sample_rate})
    return final, timeline


def global_loudness_pass(samples: list[int]) -> tuple[list[int], dict[str, Any]]:
    if not samples:
        raise RuntimeError("assembly_empty")
    rms = math.sqrt(sum(sample * sample for sample in samples) / len(samples))
    target = 0.18 * 32767
    gain = 1.0 if rms == 0 else min(2.0, max(0.25, target / rms))
    rendered = [max(-32767, min(32767, int(round(sample * gain)))) for sample in samples]
    return rendered, {"passes": 1, "target_lufs": -16.0, "gain": gain}
