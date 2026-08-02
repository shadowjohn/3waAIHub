from __future__ import annotations

import tempfile
import unittest
import wave
from contextlib import redirect_stderr
from io import StringIO
from pathlib import Path
import sys
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import job


class GptSoVitsJobTest(unittest.TestCase):
    def fixture_wav(self, path: Path, seconds: int) -> Path:
        with wave.open(str(path), "wb") as output:
            output.setnchannels(1)
            output.setsampwidth(2)
            output.setframerate(16000)
            output.writeframes(b"\x00\x00" * 16000 * seconds)
        return path

    def test_rejects_non_governed_request_fields(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "request_invalid"):
            job.validate_request({"text": "測試", "mode": "design"})
        with self.assertRaisesRegex(RuntimeError, "request_invalid"):
            job.validate_request({"text": "測試", "mode": "clone", "reference_audio_path": "/tmp/a.wav"})

    def test_normalizes_a_staged_copy_only(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = self.fixture_wav(root / "reference.wav", 12)
            original = source.read_bytes()
            staged, prompt = job.normalize_reference(source, "已確認逐字稿。下一句不應進入提示。", root / "stage")

            self.assertEqual(source.read_bytes(), original)
            self.assertTrue(staged.is_file())
            self.assertGreaterEqual(job.wav_seconds(staged), 3.0)
            self.assertLessEqual(job.wav_seconds(staged), 10.0)
            self.assertTrue(prompt)

    def test_clone_and_ultimate_contexts_are_exact(self) -> None:
        digest = "a" * 64
        clone = job.validate_request({
            "text": "測試",
            "mode": "clone",
            "voice_profile_id": 7,
            "voice_context": {
                "mode": "clone",
                "voice_profile_id": 7,
                "reference_audio_sha256": digest,
                "container_path": "/data/voice_profiles/reference.wav",
            },
        })
        self.assertEqual(clone["mode"], "clone")

        with self.assertRaisesRegex(RuntimeError, "ultimate_clone_prompt_text_required"):
            job.validate_request({
                "text": "測試",
                "mode": "ultimate_clone",
                "voice_profile_id": 7,
                "voice_context": {
                    "mode": "ultimate_clone",
                    "voice_profile_id": 7,
                    "reference_audio_sha256": digest,
                    "prompt_text_sha256": digest,
                    "prompt_text_confirmed_at": "2026-08-02 00:00:00",
                    "container_path": "/data/voice_profiles/reference.wav",
                },
            })

    def test_local_models_require_transformer_runtime_assets(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for relative in [
                "checkpoints/gpt_v2.ckpt",
                "checkpoints/sovits_v2.pth",
                "pretrained_models/chinese-hubert-base/config.json",
                "pretrained_models/chinese-hubert-base/pytorch_model.bin",
                "pretrained_models/chinese-hubert-base/preprocessor_config.json",
                "pretrained_models/chinese-roberta-wwm-ext-large/config.json",
                "pretrained_models/chinese-roberta-wwm-ext-large/pytorch_model.bin",
                "pretrained_models/chinese-roberta-wwm-ext-large/tokenizer.json",
                "g2pw/config.py",
                "g2pw/g2pW.onnx",
                "g2pw/POLYPHONIC_CHARS.txt",
                "nltk_data/corpora/cmudict/cmudict",
                "nltk_data/corpora/cmudict.zip",
                "nltk_data/taggers/averaged_perceptron_tagger/averaged_perceptron_tagger.pickle",
                "nltk_data/taggers/averaged_perceptron_tagger.zip",
                "nltk_data/taggers/averaged_perceptron_tagger_eng/averaged_perceptron_tagger_eng.weights.json",
                "nltk_data/taggers/averaged_perceptron_tagger_eng.zip",
            ]:
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(b"x")
            with patch.object(job, "MODEL_ROOT", root):
                self.assertEqual({"gpt", "sovits", "hubert", "roberta", "g2pw", "nltk"}, set(job.local_model_paths()))
                (root / "pretrained_models/chinese-roberta-wwm-ext-large/tokenizer.json").unlink()
                with self.assertRaisesRegex(RuntimeError, "model_assets_unavailable"):
                    job.local_model_paths()

    def test_uses_bundled_fast_langdetect_lite_model_offline(self) -> None:
        import fast_langdetect

        detector = fast_langdetect.infer._default_detector
        original_model = detector.config.model
        original_detect = fast_langdetect.detect
        calls: list[dict[str, object]] = []

        def detect_probe(text: str, **kwargs: object) -> list[dict[str, str]]:
            calls.append({"text": text, **kwargs})
            return []

        try:
            detector.config.model = "auto"
            fast_langdetect.detect = detect_probe
            job.configure_offline_language_detector()
            self.assertEqual("lite", detector.config.model)
            fast_langdetect.detect("\u6e2c\u8a66", model="full")
            self.assertEqual("lite", calls[-1]["model"])
        finally:
            detector.config.model = original_model
            fast_langdetect.detect = original_detect

    def test_exposes_managed_roberta_to_g2pw_before_upstream_import(self) -> None:
        roberta = Path("/models/gpt_sovits/pretrained_models/chinese-roberta-wwm-ext-large")
        with patch.dict(job.os.environ, {}, clear=True):
            job.configure_local_model_environment({"roberta": roberta, "nltk": Path("/models/gpt_sovits/nltk_data")})
            self.assertEqual(str(roberta), job.os.environ["bert_path"])
            self.assertEqual("/models/gpt_sovits/nltk_data", job.os.environ["NLTK_DATA"])

    def test_main_emits_hub_stable_error_code(self) -> None:
        stderr = StringIO()
        with patch.object(job, "run_job", side_effect=RuntimeError("tts_failed")):
            with redirect_stderr(stderr):
                status = job.main(["--workspace", "/tmp/workspace", "--input", "/tmp/input", "--output", "/tmp/output"])

        self.assertEqual(2, status)
        self.assertIn("AIHUB_ERROR_CODE=tts_failed", stderr.getvalue())


if __name__ == "__main__":
    unittest.main()
