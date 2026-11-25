from io import BytesIO
from pathlib import Path
from time import perf_counter

from model import check_snapshot

CUDA_VERSION = "12.9"


class FluxError(Exception):
    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


class FluxEngine:
    def __init__(self, model_dir: Path):
        self.model_dir = model_dir
        self.pipeline = None

    def readiness(self) -> str | None:
        try:
            check_snapshot(self.model_dir)
        except (OSError, ValueError):
            return "model_not_provisioned"
        import torch

        if torch.version.cuda != CUDA_VERSION or not torch.cuda.is_available():
            return "gpu_unavailable"
        try:
            if not torch.cuda.is_bf16_supported():
                return "gpu_unavailable"
            torch.ones(1, device="cuda", dtype=torch.bfloat16).add_(1).item()
        except RuntimeError:
            return "gpu_unavailable"
        return None

    def generate(self, prompt: str, width: int, height: int, seed: int) -> tuple[bytes, int]:
        ready_error = self.readiness()
        if ready_error:
            raise FluxError(ready_error)

        import torch

        start = perf_counter()
        try:
            if self.pipeline is None:
                from diffusers import Flux2KleinPipeline

                pipeline = Flux2KleinPipeline.from_pretrained(
                    str(self.model_dir),
                    torch_dtype=torch.bfloat16,
                    local_files_only=True,
                    use_safetensors=True,
                )
                pipeline.enable_model_cpu_offload()
                self.pipeline = pipeline

            with torch.inference_mode():
                image = self.pipeline(
                    prompt=prompt, width=width, height=height,
                    num_inference_steps=4, guidance_scale=1.0,
                    generator=torch.Generator(device="cpu").manual_seed(seed),
                ).images[0]
                try:
                    with BytesIO() as output:
                        image.save(output, format="PNG")
                        return output.getvalue(), round((perf_counter() - start) * 1000)
                finally:
                    image.close()
        except torch.cuda.OutOfMemoryError as error:
            self.pipeline = None
            raise FluxError("gpu_out_of_memory") from error
