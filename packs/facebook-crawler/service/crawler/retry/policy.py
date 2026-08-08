"""Classified errors, exit codes, and exponential backoff retry."""

from __future__ import annotations

import random
import time
from dataclasses import dataclass
from enum import Enum
from typing import Callable, TypeVar

# Exit codes (stable for schedulers)
EXIT_OK = 0
EXIT_ERROR = 1
EXIT_SESSION_FAILURE = 2
EXIT_ZERO_RECORDS = 3


class ErrorClass(str, Enum):
    OK = "ok"
    ZERO_RECORDS = "zero_records"
    TRANSIENT = "transient"
    PROXY = "proxy"
    SESSION = "session"
    RATE_LIMIT = "rate_limit"
    FATAL = "fatal"


@dataclass
class RetryConfig:
    max_attempts: int = 1
    base_delay_ms: int = 800
    max_delay_ms: int = 12_000
    jitter_ratio: float = 0.25
    # Whether empty extraction retries (default: no — often a real empty page)
    retry_zero_records: bool = False


@dataclass
class AttemptResult:
    error_class: ErrorClass
    exit_code: int
    message: str = ""
    records: list | None = None
    meta: dict | None = None


def should_retry(error_class: ErrorClass, cfg: RetryConfig) -> bool:
    if error_class in (ErrorClass.OK, ErrorClass.SESSION, ErrorClass.FATAL):
        return False
    if error_class == ErrorClass.ZERO_RECORDS:
        return cfg.retry_zero_records
    if error_class in (ErrorClass.TRANSIENT, ErrorClass.PROXY, ErrorClass.RATE_LIMIT):
        return True
    return False


def exit_code_for(error_class: ErrorClass, *, allow_zero: bool = False) -> int:
    if error_class == ErrorClass.OK:
        return EXIT_OK
    if error_class == ErrorClass.ZERO_RECORDS:
        return EXIT_OK if allow_zero else EXIT_ZERO_RECORDS
    if error_class == ErrorClass.SESSION:
        return EXIT_SESSION_FAILURE
    return EXIT_ERROR


def backoff_sleep(attempt_index: int, cfg: RetryConfig) -> float:
    """Sleep for exponential backoff. attempt_index is 0-based after a failure."""
    delay = min(cfg.max_delay_ms, cfg.base_delay_ms * (2**attempt_index))
    jitter = delay * cfg.jitter_ratio * random.uniform(-1, 1)
    seconds = max(0.05, (delay + jitter) / 1000.0)
    time.sleep(seconds)
    return seconds


T = TypeVar("T")


def run_with_retry(
    fn: Callable[[], AttemptResult],
    cfg: RetryConfig,
) -> AttemptResult:
    """Run fn with classified retry. Session/fatal never retry."""
    last: AttemptResult | None = None
    attempts_meta: list[dict] = []

    for attempt in range(max(1, cfg.max_attempts)):
        result = fn()
        attempts_meta.append(
            {
                "attempt": attempt + 1,
                "error_class": result.error_class.value,
                "exit_code": result.exit_code,
                "message": result.message,
            }
        )
        last = result
        if result.error_class == ErrorClass.OK:
            break
        if not should_retry(result.error_class, cfg):
            break
        if attempt + 1 >= cfg.max_attempts:
            break
        slept = backoff_sleep(attempt, cfg)
        attempts_meta[-1]["backoff_seconds"] = round(slept, 3)

    assert last is not None
    meta = dict(last.meta or {})
    meta["attempts"] = attempts_meta
    meta["attempt_count"] = len(attempts_meta)
    last.meta = meta
    return last


# --- Decision table (document as code) ---
# Signal                     | ErrorClass     | Retry?              | Exit
# ok + records               | OK             | no                  | 0
# ok + 0 records             | ZERO_RECORDS   | if retry_zero_recs  | 3 (or 0 if allow)
# login wall / checkpoint    | SESSION        | NEVER               | 2
# network / timeout / nav    | TRANSIENT      | yes                 | 1
# proxy connect fail         | PROXY          | yes (same endpoint) | 1
# rate limit marker          | RATE_LIMIT     | yes                 | 1
# unknown exception          | FATAL / TRANSIENT depending on type  | 1
