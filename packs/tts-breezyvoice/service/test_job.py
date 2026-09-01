from __future__ import annotations

import hashlib
import json
import subprocess
import wave
from pathlib import Path

import pytest

import job
import provision


MODEL_ID = "MediaTek-Research/BreezyVoice"
MODEL_REVISION = "a" * 40
UPSTREAM_REVISION = "b" * 40


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for block in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def write_pcm16_wav(path: Path) -> None:
    with wave.open(str(path), "wb") as output:
        output.setnchannels(1)
        output.setsampwidth(2)
        output.setframerate(24000)
        output.writeframes(b"\x00\x00" * 240)


def write_manifest(model_dir: Path) -> None:
    weights = model_dir / "weights.bin"
    manifest = {
        "model_id": MODEL_ID,
        "revision": MODEL_REVISION,
        "files": [{
            "path": "weights.bin",
            "sha256": sha256_file(weights),
            "size_bytes": weights.stat().st_size,
        }],
    }
    (model_dir / "model-manifest.json").write_text(
        json.dumps(manifest, sort_keys=True, separators=(",", ":")) + "\n",
        encoding="utf-8",
    )


def make_job_fixture(tmp_path: Path) -> dict[str, Path | str]:
    workspace = tmp_path / "workspace"
    input_dir = workspace / "input"
    output_dir = workspace / "output"
    input_dir.mkdir(parents=True)
    output_dir.mkdir()
    reference = tmp_path / "reference.wav"
    write_pcm16_wav(reference)
    model_dir = tmp_path / "model"
    model_dir.mkdir()
    (model_dir / "weights.bin").write_bytes(b"pinned model")
    write_manifest(model_dir)
    transcript = "這是已確認的參考語音逐字稿。"
    reference_hash = sha256_file(reference)
    transcript_hash = sha256_text(transcript)
    request = {
        "text": "這是要合成的內容。",
        "mode": "ultimate_clone",
        "seed": 123,
        "seed_policy": "best_effort",
        "voice_context": {
            "reference_audio_sha256": reference_hash,
            "transcript_sha256": transcript_hash,
            "confirmation_state": "confirmed",
        },
    }
    runner_config = {
        "model": MODEL_ID,
        "model_revision": MODEL_REVISION,
        "upstream_revision": UPSTREAM_REVISION,
        "model_dir": str(model_dir),
        "max_input_chars": 100,
        "timeout_seconds": 10,
        "device": "cuda",
        "voice_profile_snapshot": {
            "reference_audio_sha256": reference_hash,
            "transcript": transcript,
            "transcript_sha256": transcript_hash,
            "confirmation_state": "confirmed",
        },
    }
    (input_dir / "request.json").write_text(json.dumps(request), encoding="utf-8")
    (input_dir / "runner_config.json").write_text(json.dumps(runner_config), encoding="utf-8")
    return {
        "workspace": workspace,
        "input_dir": input_dir,
        "output_dir": output_dir,
        "config": input_dir / "runner_config.json",
        "reference": reference,
        "model_dir": model_dir,
        "transcript": transcript,
    }


def run_fixture(fixture: dict[str, Path | str], **kwargs: object) -> dict[str, object]:
    return job.run_job(
        fixture["workspace"],
        fixture["input_dir"],
        fixture["output_dir"],
        fixture["config"],
        reference_path=fixture["reference"],
        **kwargs,
    )


def test_provision_uses_injected_downloader_and_writes_canonical_manifest(tmp_path: Path) -> None:
    model_dir = tmp_path / "model"
    calls: list[tuple[str, str, Path]] = []

    def downloader(model_id: str, revision: str, destination: Path) -> None:
        calls.append((model_id, revision, destination))
        (destination / "nested").mkdir()
        (destination / "nested" / "weights.bin").write_bytes(b"fixture weights")

    manifest = provision.provision_models(model_dir, MODEL_ID, MODEL_REVISION, downloader=downloader)

    assert calls == [(MODEL_ID, MODEL_REVISION, calls[0][2])]
    assert calls[0][2].parent == model_dir.parent
    assert calls[0][2] != model_dir
    assert manifest["model_id"] == MODEL_ID
    assert manifest["revision"] == MODEL_REVISION
    assert manifest["files"] == [{
        "path": "nested/weights.bin",
        "sha256": sha256_file(model_dir / "nested" / "weights.bin"),
        "size_bytes": 15,
    }]
    assert json.loads((model_dir / "model-manifest.json").read_text(encoding="utf-8")) == manifest


@pytest.mark.parametrize("revision", ["main", "a" * 39, "g" * 40])
def test_provision_rejects_mutable_or_non_sha_revisions_without_downloading(tmp_path: Path, revision: str) -> None:
    calls: list[tuple[str, str, Path]] = []

    def downloader(model_id: str, requested_revision: str, destination: Path) -> None:
        calls.append((model_id, requested_revision, destination))

    with pytest.raises(RuntimeError, match="^model_revision_invalid$"):
        provision.provision_models(tmp_path / "model", MODEL_ID, revision, downloader=downloader)
    assert calls == []


@pytest.mark.parametrize("layout", ["staged", "destination"])
def test_provision_refuses_symlinked_staged_or_destination_layout(tmp_path: Path, layout: str) -> None:
    model_dir = tmp_path / "model"
    target = tmp_path / "target"
    target.mkdir()
    probe = tmp_path / "symlink-probe"
    try:
        probe.symlink_to(target, target_is_directory=True)
        probe.unlink()
        if layout == "destination":
            model_dir.symlink_to(target, target_is_directory=True)

            def downloader(model_id: str, revision: str, destination: Path) -> None:
                raise AssertionError("symlinked destination must be rejected before downloading")
        else:
            outside = tmp_path / "outside.bin"
            outside.write_bytes(b"outside")

            def downloader(model_id: str, revision: str, destination: Path) -> None:
                (destination / "weights.bin").symlink_to(outside)
    except OSError:
        pytest.skip("symlink creation is unavailable on this host")

    with pytest.raises(RuntimeError, match="^(model_dir_invalid|model_layout_invalid)$"):
        provision.provision_models(model_dir, MODEL_ID, MODEL_REVISION, downloader=downloader)


def test_rejects_symlink_reference_audio(tmp_path: Path) -> None:
    fixture = make_job_fixture(tmp_path)
    symlink = tmp_path / "reference-link.wav"
    try:
        symlink.symlink_to(fixture["reference"])
    except OSError:
        pytest.skip("symlink creation is unavailable on this host")

    with pytest.raises(RuntimeError, match="^reference_invalid$"):
        job.run_job(
            fixture["workspace"],
            fixture["input_dir"],
            fixture["output_dir"],
            fixture["config"],
            reference_path=symlink,
        )


def test_rejects_manifest_tampering_before_inference(tmp_path: Path) -> None:
    fixture = make_job_fixture(tmp_path)
    (fixture["model_dir"] / "weights.bin").write_bytes(b"tampered")

    with pytest.raises(RuntimeError, match="^model_manifest_invalid$"):
        run_fixture(fixture)


@pytest.mark.parametrize(
    ("mutate", "error_code"),
    [
        ("path_escape", "workspace_path_invalid"),
        ("wrong_mode", "mode_invalid"),
        ("missing_transcript", "transcript_missing"),
    ],
)
def test_rejects_workspace_escape_mode_and_missing_transcript(tmp_path: Path, mutate: str, error_code: str) -> None:
    fixture = make_job_fixture(tmp_path)
    if mutate == "path_escape":
        outside_input = tmp_path / "outside-input"
        outside_input.mkdir()
        with pytest.raises(RuntimeError, match=f"^{error_code}$"):
            job.run_job(
                fixture["workspace"],
                outside_input,
                fixture["output_dir"],
                fixture["config"],
                reference_path=fixture["reference"],
            )
        return

    request_path = fixture["input_dir"] / "request.json"
    request = json.loads(request_path.read_text(encoding="utf-8"))
    config_path = fixture["config"]
    config = json.loads(config_path.read_text(encoding="utf-8"))
    if mutate == "wrong_mode":
        request["mode"] = "clone"
        request_path.write_text(json.dumps(request), encoding="utf-8")
    else:
        del config["voice_profile_snapshot"]["transcript"]
        config_path.write_text(json.dumps(config), encoding="utf-8")

    with pytest.raises(RuntimeError, match=f"^{error_code}$"):
        run_fixture(fixture)


def test_validates_mocked_wav_and_writes_best_effort_provenance(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    fixture = make_job_fixture(tmp_path)
    commands: list[tuple[list[str], dict[str, object]]] = []

    def fake_run(command: list[str], **kwargs: object) -> subprocess.CompletedProcess[str]:
        commands.append((command, kwargs))
        write_pcm16_wav(Path(command[-1]))
        return subprocess.CompletedProcess(command, 0)

    monkeypatch.setattr(job.subprocess, "run", fake_run)
    metadata = run_fixture(fixture)

    output = fixture["output_dir"] / "generated_audio.wav"
    expected = [
        job.sys.executable,
        "/opt/breezyvoice/single_inference.py",
        "--content_to_synthesize",
        "這是要合成的內容。",
        "--speaker_prompt_audio_path",
        "/data/voice_profiles/reference.wav",
        "--speaker_prompt_text_transcription",
        fixture["transcript"],
        "--output_path",
        str(output),
    ]
    assert commands == [(expected, {"cwd": "/opt/breezyvoice", "check": True, "timeout": 10, "shell": False})]
    assert metadata["seed"] == 123
    assert metadata["seed_applied"] is False
    assert metadata["reproducibility"] == "best_effort"
    assert metadata["reference_audio_sha256"] == metadata["reference_audio_sha256"].lower()
    assert metadata["transcript_sha256"] == metadata["transcript_sha256"].lower()
    assert json.loads((fixture["output_dir"] / "synthesis_metadata.json").read_text(encoding="utf-8")) == metadata
    with wave.open(str(output), "rb") as generated:
        assert (generated.getnchannels(), generated.getframerate(), generated.getsampwidth()) == (1, 24000, 2)
