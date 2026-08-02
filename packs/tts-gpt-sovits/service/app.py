from __future__ import annotations

import gc
import json
import os
import re
import secrets
import threading
from contextlib import contextmanager
from pathlib import Path
from typing import Any

from fastapi import FastAPI, Header
from fastapi.responses import JSONResponse

import job


app = FastAPI(title="3waAIHub GPT-SoVITS")
_MODEL: Any | None = None
# ponytail: one resident inference at a time; add a queued service worker only when measured throughput needs it.
_MODEL_WORK_LOCK = threading.RLock()
_MODEL_WORK_DEPTH = 0
_IDLE_TIMER: threading.Timer | None = None
_ACTIVE_JOBS: dict[str, threading.Event] = {}
_ACTIVE_JOBS_LOCK = threading.RLock()
RUN_ID = re.compile(r"^[a-z0-9][a-z0-9_.-]{0,95}$")
TERMINAL_STATES = {"succeeded", "failed", "cancelled"}


def env_int(name: str, fallback: int) -> int:
    try:
        return int(os.getenv(name, str(fallback)))
    except ValueError:
        return fallback


def resident_idle_seconds() -> int:
    return max(0, env_int("GPT_SOVITS_IDLE_UNLOAD_SECONDS", 0))


def service_data_dir() -> Path:
    return Path(os.getenv("GPT_SOVITS_SERVICE_DATA_DIR", "/data/service"))


def _cancel_idle_timer() -> None:
    global _IDLE_TIMER
    if _IDLE_TIMER is not None:
        _IDLE_TIMER.cancel()
        _IDLE_TIMER = None


def _unload_idle_model() -> None:
    global _IDLE_TIMER, _MODEL
    with _MODEL_WORK_LOCK:
        _IDLE_TIMER = None
        if _MODEL_WORK_DEPTH or _MODEL is None:
            return
        _MODEL = None
        gc.collect()
        try:
            import torch
            if torch.cuda.is_available():
                torch.cuda.empty_cache()
        except ImportError:
            pass


def _schedule_idle_unload() -> None:
    global _IDLE_TIMER
    seconds = resident_idle_seconds()
    if seconds <= 0 or _MODEL is None:
        return
    _cancel_idle_timer()
    _IDLE_TIMER = threading.Timer(seconds, _unload_idle_model)
    _IDLE_TIMER.daemon = True
    _IDLE_TIMER.start()


@contextmanager
def model_work() -> Any:
    global _MODEL_WORK_DEPTH
    with _MODEL_WORK_LOCK:
        _cancel_idle_timer()
        _MODEL_WORK_DEPTH += 1
        try:
            yield
        finally:
            _MODEL_WORK_DEPTH -= 1
            if _MODEL_WORK_DEPTH == 0:
                _schedule_idle_unload()


def resident_runtime() -> Any:
    global _MODEL
    if _MODEL is None:
        _MODEL = job.load_runtime()
    return _MODEL


def reset_resident_state() -> None:
    global _IDLE_TIMER, _MODEL_WORK_DEPTH, _MODEL
    _cancel_idle_timer()
    _MODEL_WORK_DEPTH = 0
    _MODEL = None
    _ACTIVE_JOBS.clear()


def internal_authorized(token: str | None) -> bool:
    expected = os.getenv("GPT_SOVITS_INTERNAL_JOB_TOKEN", "")
    return bool(expected and token and secrets.compare_digest(expected, token))


def resident_jobs_root() -> Path:
    root = service_data_dir() / "resident_jobs"
    if root.exists() and (root.is_symlink() or not root.is_dir()):
        raise RuntimeError("resident_job_invalid")
    root.mkdir(parents=True, exist_ok=True)
    if root.is_symlink():
        raise RuntimeError("resident_job_invalid")
    return root.resolve(strict=True)


def resident_stage(run_id: str) -> Path:
    if not isinstance(run_id, str) or not RUN_ID.fullmatch(run_id):
        raise RuntimeError("resident_job_invalid")
    root = resident_jobs_root()
    stage = root / run_id
    if not stage.is_dir() or stage.is_symlink():
        raise RuntimeError("resident_job_invalid")
    resolved = stage.resolve(strict=True)
    if root not in resolved.parents:
        raise RuntimeError("resident_job_invalid")
    return resolved


def resident_regular(stage: Path, *parts: str) -> Path:
    target = stage.joinpath(*parts)
    if not target.is_file() or target.is_symlink():
        raise RuntimeError("resident_job_invalid")
    resolved = target.resolve(strict=True)
    if stage not in resolved.parents:
        raise RuntimeError("resident_job_invalid")
    return resolved


def terminal_state(stage: Path) -> str:
    try:
        payload = json.loads(resident_regular(stage, "terminal.json").read_text(encoding="utf-8"))
    except (OSError, RuntimeError, ValueError):
        return "unknown"
    return str(payload.get("state")) if isinstance(payload, dict) and set(payload) == {"state"} and payload.get("state") in TERMINAL_STATES else "unknown"


def write_terminal_state(stage: Path, state: str) -> None:
    if state not in TERMINAL_STATES:
        raise RuntimeError("resident_job_invalid")
    target = stage / "terminal.json"
    temporary = stage / ".terminal.json.tmp"
    if (target.exists() and (target.is_symlink() or not target.is_file())) or (temporary.exists() and (temporary.is_symlink() or not temporary.is_file())):
        raise RuntimeError("resident_job_invalid")
    with temporary.open("w", encoding="utf-8") as handle:
        json.dump({"state": state}, handle, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    temporary.replace(target)


def response_error(status: int, error: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"ok": False, "error": error})


def internal_error(status: int, error: str) -> JSONResponse:
    return response_error(status, error)


@app.get("/health")
def health() -> dict[str, Any]:
    try:
        job.local_model_paths()
        models_ready = True
    except RuntimeError:
        models_ready = False
    return {
        "ok": True,
        "service": "tts-gpt-sovits",
        "ready": models_ready,
        "model": "GPT-SoVITS V2",
        "modes": ["clone", "ultimate_clone"],
        "execution_mode": os.getenv("GPT_SOVITS_EXECUTION_MODE", "isolated"),
        "model_state": "ready" if _MODEL is not None else "cold",
    }


@app.post("/v1/tts", response_model=None)
def tts() -> JSONResponse:
    return response_error(405, "async_required")


@app.get("/internal/capacity", response_model=None)
def internal_capacity(x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, Any] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    with _ACTIVE_JOBS_LOCK, _MODEL_WORK_LOCK:
        state = "running" if _ACTIVE_JOBS or _MODEL_WORK_DEPTH else "ready" if _MODEL is not None else "cold"
        return {"model_state": state, "active_runs": len(_ACTIVE_JOBS)}


@app.get("/internal/jobs/{run_id}", response_model=None)
def internal_job_status(run_id: str, x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    try:
        stage = resident_stage(run_id)
    except RuntimeError:
        return internal_error(404, "resident_job_not_found")
    with _ACTIVE_JOBS_LOCK:
        state = "running" if run_id in _ACTIVE_JOBS else terminal_state(stage)
    return {"run_id": run_id, "state": state}


@app.post("/internal/jobs/{run_id}/cancel", response_model=None)
def internal_job_cancel(run_id: str, x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    if not RUN_ID.fullmatch(run_id):
        return internal_error(404, "resident_job_not_found")
    with _ACTIVE_JOBS_LOCK:
        cancelled = _ACTIVE_JOBS.get(run_id)
        if cancelled is None:
            return internal_error(404, "resident_job_not_found")
        cancelled.set()
    return {"run_id": run_id, "state": "running"}


@app.post("/internal/jobs", response_model=None)
def internal_job_start(payload: dict[str, Any], x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    if not isinstance(payload, dict) or set(payload) != {"run_id"} or not isinstance(payload.get("run_id"), str):
        return internal_error(400, "resident_job_invalid")
    run_id = payload["run_id"]
    try:
        stage = resident_stage(run_id)
        request_path = resident_regular(stage, "input", "request.json")
        resident_regular(stage, "input", "runner_config.json")
        reference_path = resident_regular(stage, "input", "source")
    except RuntimeError:
        return internal_error(400, "resident_job_invalid")

    terminal = terminal_state(stage)
    if terminal != "unknown":
        return {"run_id": run_id, "state": terminal}
    cancelled = threading.Event()
    with _ACTIVE_JOBS_LOCK:
        if _ACTIVE_JOBS:
            return internal_error(409, "resident_job_active")
        _ACTIVE_JOBS[run_id] = cancelled

    state = "failed"
    try:
        with model_work():
            job.run_job(
                stage,
                request_path.parent,
                stage / "output",
                runtime_loader=resident_runtime,
                managed_reference_path=reference_path,
                cancelled=cancelled.is_set,
            )
        state = "cancelled" if cancelled.is_set() else "succeeded"
    except RuntimeError as error:
        state = "cancelled" if str(error) == "job_cancelled" else "failed"
    except Exception:
        state = "failed"
    try:
        write_terminal_state(stage, state)
    except Exception:
        return internal_error(500, "resident_job_state_failed")
    finally:
        with _ACTIVE_JOBS_LOCK:
            _ACTIVE_JOBS.pop(run_id, None)
    return {"run_id": run_id, "state": state}
