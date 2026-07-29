#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import sys
from pathlib import Path

import edge_tts

from synthesize import RunnerError, load_voice_catalog, remove_if_regular


MAX_DEMO_BYTES = 1024 * 1024


def fail() -> None:
    raise RunnerError("edge_tts_demo_initialization_failed")


def remove_regular_temp(path: Path) -> None:
    try:
        if path.exists() and not path.is_symlink() and path.is_file():
            path.unlink()
    except OSError:
        pass


def checked_size(path: Path) -> int:
    try:
        if path.is_symlink() or not path.is_file():
            fail()
        size = path.stat().st_size
    except OSError:
        fail()
    if not 0 < size <= MAX_DEMO_BYTES:
        fail()
    return size


def availability_entry(path: Path, voice: dict[str, str]) -> dict[str, str | int]:
    size = checked_size(path)
    try:
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
    except OSError:
        fail()
    return {"id": voice["id"], "file": voice["demo_file"], "bytes": size, "sha256": digest}


def write_availability(path: Path, voices: list[dict[str, str | int]]) -> None:
    temporary = path.with_name("." + path.name + ".tmp")
    try:
        remove_if_regular(temporary)
        encoded = json.dumps({"version": 1, "voices": voices}, ensure_ascii=True, separators=(",", ":")) + "\n"
        temporary.write_text(encoded, encoding="utf-8")
        temporary.replace(path)
    except (OSError, UnicodeEncodeError, RunnerError):
        fail()


def run_demos(output_dir: Path) -> tuple[int, int]:
    if output_dir.is_symlink() or not output_dir.is_dir():
        fail()
    try:
        voices = load_voice_catalog()
    except RunnerError:
        fail()
    successes: list[dict[str, str | int]] = []
    failed = 0
    for voice in voices:
        temporary = output_dir / ("." + voice["demo_file"] + ".tmp")
        final = output_dir / voice["demo_file"]
        try:
            remove_if_regular(temporary)
            edge_tts.Communicate(
                voice["demo_text"], voice["id"], rate="+0%", volume="+0%", pitch="+0Hz"
            ).save_sync(str(temporary))
            checked_size(temporary)
            temporary.replace(final)
            successes.append(availability_entry(final, voice))
        except Exception:
            remove_regular_temp(temporary)
            failed += 1
    if not successes:
        fail()
    try:
        write_availability(output_dir / "available.json", successes)
    except RunnerError:
        fail()
    return len(successes), failed


def main() -> int:
    try:
        succeeded, failed = run_demos(Path("/workspace/output"))
    except RunnerError as error:
        print(f"AIHUB_ERROR_CODE={error.code}", file=sys.stderr)
        return 1
    except Exception:
        print("AIHUB_ERROR_CODE=edge_tts_demo_initialization_failed", file=sys.stderr)
        return 1
    print(f"succeeded={succeeded} failed={failed}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
