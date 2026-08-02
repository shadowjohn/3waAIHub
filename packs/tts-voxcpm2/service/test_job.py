import hashlib
import io
import json
import os
import sys
import tempfile
import types
import unittest
from contextlib import redirect_stderr
from pathlib import Path
from unittest.mock import patch

SERVICE_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SERVICE_DIR))

import job


class UltimateCloneJobTests(unittest.TestCase):
    wav = b"RIFFmanaged-reference"
    prompt_text = "private confirmed transcript"
    confirmed_at = "2026-07-31 12:34:56"

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.workspace = Path(self.temporary.name)
        (self.workspace / "input").mkdir()
        (self.workspace / "output").mkdir()
        self.reference_sha256 = hashlib.sha256(self.wav).hexdigest()
        self.prompt_sha256 = hashlib.sha256(self.prompt_text.encode()).hexdigest()

    def tearDown(self):
        self.temporary.cleanup()

    def request(self, **overrides):
        value = {
            "text": "target text",
            "mode": "ultimate_clone",
            "control": "steady",
            "seed": 42,
            "seed_policy": "fixed",
            "model": "voxcpm2",
            "voice_profile_id": 37,
            "prompt_text": self.prompt_text,
            "waveform_preview": False,
            "voice_context": {
                "mode": "ultimate_clone",
                "voice_profile_id": 37,
                "reference_audio_sha256": self.reference_sha256,
                "prompt_text_sha256": self.prompt_sha256,
                "prompt_text_confirmed_at": self.confirmed_at,
                "container_path": "/data/voice_profiles/reference.wav",
            },
        }
        value.update(overrides)
        return value

    def write_input(self, request):
        config = {
            "allowlist": "voxcpm2",
            "alias": "voxcpm2",
            "model": {
                "model": "/models/voxcpm2/model",
                "label": "VoxCPM2",
                "version": "2.0.3",
                "sample_rate": 48000,
            },
        }
        (self.workspace / "input" / "request.json").write_text(json.dumps(request), encoding="utf-8")
        (self.workspace / "input" / "runner_config.json").write_text(json.dumps(config), encoding="utf-8")

    def run_job(self):
        job.run_job(
            self.workspace,
            self.workspace / "input",
            self.workspace / "output",
            self.workspace / "input" / "runner_config.json",
        )
        return json.loads((self.workspace / "output" / "synthesis_metadata.json").read_text(encoding="utf-8"))

    def managed_wav(self, content=None):
        return patch.multiple(
            job,
            regular=lambda path: path == Path("/data/voice_profiles/reference.wav"),
        ), patch.object(Path, "read_bytes", return_value=self.wav if content is None else content)

    def test_ultimate_clone_passes_managed_prompt_to_real_tts_and_keeps_metadata_private(self):
        captured = []
        loads = []
        loaded_model = types.SimpleNamespace(tts_model=types.SimpleNamespace(device="cuda:0"))

        class TtsRequest:
            def __init__(self, **values):
                captured.append(values)

        class VoxCPM:
            @classmethod
            def from_pretrained(cls, *args, **kwargs):
                loads.append((args, kwargs))
                return loaded_model

        def write_real_wav(path, request, seed):
            job.write_pcm(path, 48000, [100, -100] * 240)

        fake_app = types.SimpleNamespace(TtsRequest=TtsRequest, write_real_wav=write_real_wav, _MODEL=None)
        fake_torch = types.SimpleNamespace(cuda=types.SimpleNamespace(is_available=lambda: True))
        fake_voxcpm = types.SimpleNamespace(VoxCPM=VoxCPM)
        self.write_input(self.request())
        managed, wav_bytes = self.managed_wav()
        with managed, wav_bytes, patch.dict(sys.modules, {"app": fake_app, "torch": fake_torch, "voxcpm": fake_voxcpm}), patch.dict(os.environ, {"VOXCPM2_JOB_FAKE_SYNTHESIS": "", "VOXCPM2_TORCH_COMPILE": "0"}):
            metadata = self.run_job()

        self.assertEqual([
            (("/models/voxcpm2/model",), {"load_denoiser": False, "optimize": False, "device": "cuda"}),
        ], loads)
        self.assertGreater(len(captured), 0)
        for request in captured:
            self.assertEqual("/data/voice_profiles/reference.wav", request["reference_wav_path"])
            self.assertEqual("/data/voice_profiles/reference.wav", request["prompt_wav_path"])
            self.assertEqual(self.prompt_text, request["prompt_text"])
        self.assertEqual({"type": "cuda", "real_inference": True}, metadata["device"])
        checkpoint = json.loads(next((self.workspace / "checkpoints" / "chunks").glob("*.json")).read_text(encoding="utf-8"))
        self.assertEqual("cuda", checkpoint["context"]["device"])
        self.assertEqual(self.reference_sha256, metadata["voice_context"]["reference_audio_sha256"])
        self.assertEqual(self.prompt_sha256, metadata["voice_context"]["prompt_text_sha256"])
        self.assertNotIn("container_path", metadata["voice_context"])
        self.assertNotIn("model", metadata["model"])
        public = json.dumps(metadata, ensure_ascii=False)
        self.assertNotIn(self.prompt_text, public)
        self.assertNotIn("voice_profile_id", public)
        self.assertNotIn("prompt_text_confirmed_at", public)
        self.assertNotIn("/data/voice_profiles", public)
        self.assertNotIn("/models/voxcpm2", public)

    def test_real_synthesis_rejects_cpu_model_without_retry_or_cuda_metadata(self):
        loads = []
        writes = []
        cuda_checks = []
        loaded_model = types.SimpleNamespace(tts_model=types.SimpleNamespace(device="cpu"))

        class VoxCPM:
            @classmethod
            def from_pretrained(cls, *args, **kwargs):
                loads.append((args, kwargs))
                return loaded_model

        def write_real_wav(path, request, seed):
            writes.append(seed)
            job.write_pcm(path, 48000, [100, -100] * 240)

        def cuda_available():
            cuda_checks.append(True)
            return True

        fake_app = types.SimpleNamespace(TtsRequest=lambda **values: values, write_real_wav=write_real_wav, _MODEL=None)
        fake_torch = types.SimpleNamespace(cuda=types.SimpleNamespace(is_available=cuda_available))
        fake_voxcpm = types.SimpleNamespace(VoxCPM=VoxCPM)
        self.write_input(self.request())
        managed, wav_bytes = self.managed_wav()
        with managed, wav_bytes, patch.dict(sys.modules, {"app": fake_app, "torch": fake_torch, "voxcpm": fake_voxcpm}), patch.dict(os.environ, {"VOXCPM2_JOB_FAKE_SYNTHESIS": ""}):
            with self.assertRaisesRegex(RuntimeError, "^gpu_unavailable$"):
                self.run_job()

        self.assertEqual(1, len(loads))
        self.assertEqual(1, len(cuda_checks), "gpu_unavailable must not be retried")
        self.assertEqual([], writes)
        self.assertFalse((self.workspace / "output" / "synthesis_metadata.json").exists())

    def test_real_synthesis_rejects_cuda_loss_after_inference(self):
        writes = []
        loaded_model = types.SimpleNamespace(tts_model=types.SimpleNamespace(device="cuda"))

        class VoxCPM:
            @classmethod
            def from_pretrained(cls, *args, **kwargs):
                return loaded_model

        def write_real_wav(path, request, seed):
            writes.append(seed)
            job.write_pcm(path, 48000, [100, -100] * 240)
            loaded_model.tts_model.device = "cpu"

        fake_app = types.SimpleNamespace(TtsRequest=lambda **values: values, write_real_wav=write_real_wav, _MODEL=None)
        fake_torch = types.SimpleNamespace(cuda=types.SimpleNamespace(is_available=lambda: True))
        fake_voxcpm = types.SimpleNamespace(VoxCPM=VoxCPM)
        self.write_input(self.request())
        managed, wav_bytes = self.managed_wav()
        with managed, wav_bytes, patch.dict(sys.modules, {"app": fake_app, "torch": fake_torch, "voxcpm": fake_voxcpm}), patch.dict(os.environ, {"VOXCPM2_JOB_FAKE_SYNTHESIS": ""}):
            with self.assertRaisesRegex(RuntimeError, "^gpu_unavailable$"):
                self.run_job()

        self.assertEqual(1, len(writes), "CUDA loss must terminate without chunk retries")
        self.assertFalse((self.workspace / "output" / "synthesis_metadata.json").exists())

    def test_cli_emits_one_stable_error_marker_for_every_terminal_failure(self):
        for error, expected in [
            (RuntimeError("gpu_unavailable"), "gpu_unavailable"),
            (RuntimeError("not a stable code"), "runtime_execution_failed"),
            (ValueError("unexpected"), "runtime_execution_failed"),
        ]:
            with self.subTest(error=error):
                stderr = io.StringIO()
                with patch.object(job, "main", side_effect=error), redirect_stderr(stderr):
                    self.assertEqual(1, job.cli())
                self.assertEqual(f"AIHUB_ERROR_CODE={expected}\n", stderr.getvalue())

    def test_ultimate_clone_rejects_untrusted_context_before_synthesis(self):
        cases = []
        missing_text = self.request()
        del missing_text["prompt_text"]
        cases.append((missing_text, self.wav))
        changed_text = self.request()
        changed_text["voice_context"]["prompt_text_sha256"] = "0" * 64
        cases.append((changed_text, self.wav))
        cases.append((self.request(), b"changed-wav"))
        extra_context = self.request()
        extra_context["voice_context"]["extra"] = "forbidden"
        cases.append((extra_context, self.wav))

        for request, wav in cases:
            with self.subTest(request=request, wav=wav):
                self.write_input(request)
                managed, wav_bytes = self.managed_wav(wav)
                with managed, wav_bytes, patch.object(job, "synthesize_chunk") as synthesize:
                    with self.assertRaises(RuntimeError):
                        self.run_job()
                synthesize.assert_not_called()

    def test_fake_synthesis_has_exact_fake_device_attestation(self):
        self.write_input(self.request())
        managed, wav_bytes = self.managed_wav()
        with managed, wav_bytes, patch.dict(os.environ, {"VOXCPM2_JOB_FAKE_SYNTHESIS": "1"}):
            metadata = self.run_job()

        self.assertEqual({"type": "fake", "real_inference": False}, metadata["device"])
        checkpoint = json.loads(next((self.workspace / "checkpoints" / "chunks").glob("*.json")).read_text(encoding="utf-8"))
        self.assertEqual("fake", checkpoint["context"]["device"])

    def test_design_prompt_stays_private_but_separates_checkpoints(self):
        prompt = "private design prompt"
        self.write_input({
            "text": "target text",
            "mode": "design",
            "voice_prompt": prompt,
            "control": "steady",
            "seed": 42,
            "seed_policy": "fixed",
            "model": "voxcpm2",
            "waveform_preview": False,
        })
        with patch.dict(os.environ, {"VOXCPM2_JOB_FAKE_SYNTHESIS": "1"}):
            metadata = self.run_job()

        self.assertEqual({"control": "steady", "mode": "design", "sha256": metadata["voice_context"]["sha256"]}, metadata["voice_context"])
        self.assertNotIn(prompt, json.dumps(metadata, ensure_ascii=False))
        checkpoint = json.loads(next((self.workspace / "checkpoints" / "chunks").glob("*.json")).read_text(encoding="utf-8"))
        self.assertEqual(hashlib.sha256(prompt.encode()).hexdigest(), checkpoint["context"]["voice_prompt_sha256"])
        self.assertNotIn(prompt, json.dumps(checkpoint, ensure_ascii=False))

    def test_cancellation_stops_immediately_after_synthesis(self):
        cancelled = {"value": False}
        self.write_input({
            "text": "target text",
            "mode": "design",
            "voice_prompt": "steady",
            "seed": 42,
            "seed_policy": "fixed",
            "model": "voxcpm2",
            "waveform_preview": False,
        })

        def synthesize(*args, **kwargs):
            cancelled["value"] = True
            return [100, -100] * 240

        with patch.dict(os.environ, {"VOXCPM2_JOB_FAKE_SYNTHESIS": "1"}), patch.object(job, "fake_synthesize", side_effect=synthesize):
            with self.assertRaisesRegex(RuntimeError, "^job_cancelled$"):
                job.run_job(
                    self.workspace,
                    self.workspace / "input",
                    self.workspace / "output",
                    self.workspace / "input" / "runner_config.json",
                    cancelled=lambda: cancelled["value"],
                )
        self.assertFalse((self.workspace / "output" / "synthesis_metadata.json").exists())

    def test_resident_reference_must_be_a_regular_workspace_file(self):
        source = self.workspace / "input" / "source"
        outside = self.workspace.parent / "outside.wav"
        source.write_bytes(self.wav)
        outside.write_bytes(self.wav)
        self.write_input(self.request())
        managed, wav_bytes = self.managed_wav()
        seen_sources = []

        def synthesize(chunk, voice, actual_source, model, checkpoints, **kwargs):
            seen_sources.append(actual_source)
            return [100, -100] * 240

        with managed, wav_bytes, patch.dict(os.environ, {"VOXCPM2_JOB_FAKE_SYNTHESIS": "1"}):
            with self.assertRaisesRegex(RuntimeError, "^voice_profile_forbidden$"):
                self.run_job_with_reference(outside)
            with patch.object(job, "regular", side_effect=lambda path: path in {Path("/data/voice_profiles/reference.wav"), source}), patch.object(job, "synthesize_chunk", side_effect=synthesize):
                metadata = self.run_job_with_reference(source)
        self.assertEqual("ultimate_clone", metadata["controls"]["mode"])
        self.assertEqual([source], seen_sources)

    def run_job_with_reference(self, reference):
        job.run_job(
            self.workspace,
            self.workspace / "input",
            self.workspace / "output",
            self.workspace / "input" / "runner_config.json",
            managed_reference_path=reference,
        )
        return json.loads((self.workspace / "output" / "synthesis_metadata.json").read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
