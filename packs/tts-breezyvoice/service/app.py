from __future__ import annotations

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


app = FastAPI(title="3waAIHub BreezyVoice")
_RESIDENT_MODEL: dict[str, Any] | None = None
_MODEL_WORK_LOCK = threading.RLock()
_MODEL_WORK_DEPTH = 0
_ACTIVE_JOBS: dict[str, threading.Event] = {}
_ACTIVE_JOBS_LOCK = threading.RLock()
RUN_ID = re.compile(r"^[a-z0-9][a-z0-9_.-]{0,95}$")
STABLE_ERROR_CODE = re.compile(r"^[a-z][a-z0-9_]{0,79}$")
TERMINAL_STATES = {"succeeded", "failed", "cancelled"}


def execution_mode() -> str:
    return os.getenv("BREEZYVOICE_EXECUTION_MODE", "resident")


def service_data_dir() -> Path:
    return Path(os.getenv("BREEZYVOICE_SERVICE_DATA_DIR", "/data/service"))


@contextmanager
def model_work() -> Any:
    global _MODEL_WORK_DEPTH
    with _MODEL_WORK_LOCK:
        _MODEL_WORK_DEPTH += 1
        try:
            yield
        finally:
            _MODEL_WORK_DEPTH -= 1


def preload_resident_model() -> dict[str, Any]:
    global _RESIDENT_MODEL
    with _MODEL_WORK_LOCK:
        if _RESIDENT_MODEL is None:
            _RESIDENT_MODEL = job.load_resident_model(Path(os.getenv("BREEZYVOICE_MODEL_DIR", "/models/breezyvoice")))
        return _RESIDENT_MODEL


def reset_resident_state() -> None:
    global _RESIDENT_MODEL, _MODEL_WORK_DEPTH
    with _ACTIVE_JOBS_LOCK, _MODEL_WORK_LOCK:
        _RESIDENT_MODEL = None
        _MODEL_WORK_DEPTH = 0
        _ACTIVE_JOBS.clear()


def internal_authorized(token: str | None) -> bool:
    expected = os.getenv("BREEZYVOICE_INTERNAL_JOB_TOKEN", "")
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


def terminal_payload(stage: Path) -> dict[str, str] | None:
    try:
        payload = json.loads(resident_regular(stage, "terminal.json").read_text(encoding="utf-8"))
    except (OSError, RuntimeError, ValueError):
        return None
    if not isinstance(payload, dict) or not isinstance(payload.get("state"), str) or payload["state"] not in TERMINAL_STATES:
        return None
    if set(payload) == {"state"}:
        return {"state": payload["state"]}
    if (
        payload["state"] == "failed"
        and set(payload) == {"state", "error_code"}
        and isinstance(payload.get("error_code"), str)
        and STABLE_ERROR_CODE.fullmatch(payload["error_code"])
    ):
        return {"state": payload["state"], "error_code": payload["error_code"]}
    return None


def write_terminal_state(stage: Path, state: str, error_code: str | None = None) -> None:
    if state not in TERMINAL_STATES:
        raise RuntimeError("resident_job_invalid")
    payload: dict[str, str] = {"state": state}
    if state == "failed" and error_code is not None and STABLE_ERROR_CODE.fullmatch(error_code):
        payload["error_code"] = error_code
    target = stage / "terminal.json"
    temporary = stage / ".terminal.json.tmp"
    if (target.exists() and (target.is_symlink() or not target.is_file())) or (temporary.exists() and (temporary.is_symlink() or not temporary.is_file())):
        raise RuntimeError("resident_job_invalid")
    with temporary.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    temporary.replace(target)


def response_error(status: int, error: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"ok": False, "error": error})


@app.on_event("startup")
def startup() -> None:
    if execution_mode() == "resident":
        preload_resident_model()


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "ok": True,
        "service": "tts-breezyvoice",
        "ready": execution_mode() != "resident" or _RESIDENT_MODEL is not None,
        "execution_mode": execution_mode(),
        "model_state": "ready" if _RESIDENT_MODEL is not None else "cold",
        "modes": ["ultimate_clone"],
    }


@app.get("/internal/capacity", response_model=None)
def internal_capacity(x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, Any] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return response_error(403, "internal_auth_failed")
    with _ACTIVE_JOBS_LOCK, _MODEL_WORK_LOCK:
        state = "running" if _ACTIVE_JOBS or _MODEL_WORK_DEPTH else "ready" if _RESIDENT_MODEL is not None else "cold"
        return {"model_state": state, "active_runs": len(_ACTIVE_JOBS)}


@app.get("/internal/jobs/{run_id}", response_model=None)
def internal_job_status(run_id: str, x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return response_error(403, "internal_auth_failed")
    try:
        stage = resident_stage(run_id)
    except RuntimeError:
        return response_error(404, "resident_job_not_found")
    with _ACTIVE_JOBS_LOCK:
        payload = {"state": "running"} if run_id in _ACTIVE_JOBS else terminal_payload(stage) or {"state": "unknown"}
    return {"run_id": run_id, **payload}


@app.post("/internal/jobs/{run_id}/cancel", response_model=None)
def internal_job_cancel(run_id: str, x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return response_error(403, "internal_auth_failed")
    if not RUN_ID.fullmatch(run_id):
        return response_error(404, "resident_job_not_found")
    with _ACTIVE_JOBS_LOCK:
        cancelled = _ACTIVE_JOBS.get(run_id)
        if cancelled is None:
            return response_error(404, "resident_job_not_found")
        cancelled.set()
    return {"run_id": run_id, "state": "running"}


@app.post("/internal/jobs", response_model=None)
def internal_job_start(payload: dict[str, Any], x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return response_error(403, "internal_auth_failed")
    if set(payload) != {"run_id"} or not isinstance(payload.get("run_id"), str):
        return response_error(400, "resident_job_invalid")
    run_id = payload["run_id"]
    try:
        stage = resident_stage(run_id)
        request_path = resident_regular(stage, "input", "request.json")
        runner_config_path = resident_regular(stage, "input", "runner_config.json")
        reference_path = resident_regular(stage, "input", "source")
    except RuntimeError:
        return response_error(400, "resident_job_invalid")
    terminal = terminal_payload(stage)
    if terminal is not None:
        return {"run_id": run_id, **terminal}

    cancelled = threading.Event()
    with _ACTIVE_JOBS_LOCK:
        if _ACTIVE_JOBS:
            return response_error(409, "resident_job_active")
        _ACTIVE_JOBS[run_id] = cancelled

    state = "failed"
    error_code = None
    try:
        with model_work():
            job.run_job(
                stage,
                request_path.parent,
                stage / "output",
                runner_config_path,
                reference_path=reference_path,
                resident_runtime=preload_resident_model(),
                cancelled=cancelled.is_set,
            )
        state = "cancelled" if cancelled.is_set() else "succeeded"
    except RuntimeError as error:
        if str(error) == "job_cancelled":
            state = "cancelled"
        else:
            error_code = str(error) if STABLE_ERROR_CODE.fullmatch(str(error)) else "runtime_execution_failed"
    except Exception:
        error_code = "runtime_execution_failed"
    try:
        write_terminal_state(stage, state, error_code)
    except Exception:
        return response_error(500, "resident_job_state_failed")
    finally:
        with _ACTIVE_JOBS_LOCK:
            _ACTIVE_JOBS.pop(run_id, None)
    response = {"run_id": run_id, "state": state}
    if state == "failed" and error_code is not None:
        response["error_code"] = error_code
    return response
