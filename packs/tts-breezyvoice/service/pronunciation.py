"""Strict, literal pronunciation-rule compilation for BreezyVoice."""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any, Callable


MAX_EXTERNAL_RULES = 50
MAX_GLOBAL_RULES = 256
MAX_MATCH_LENGTH = 80
MAX_SPOKEN_FORM_LENGTH = 160
MAX_RULE_ID_LENGTH = 128
MAX_MODEL_TEXT_LENGTH = 8192
BOPOMOFO_READING = re.compile(r"^[ㄅㄆㄇㄈㄉㄊㄋㄌㄍㄎㄏㄐㄑㄒㄓㄔㄕㄖㄗㄘㄙㄚㄛㄜㄝㄞㄟㄠㄡㄢㄣㄤㄥㄦㄧㄨㄩ˙]+[1-5]$")


class PronunciationError(RuntimeError):
    pass


def _invalid() -> None:
    raise PronunciationError("invalid_pronunciation_rules")


def _has_control(value: str) -> bool:
    return any(ord(character) < 32 or ord(character) == 127 for character in value)


def _literal_match(value: Any) -> str:
    if not isinstance(value, str) or not 1 <= len(value) <= MAX_MATCH_LENGTH or _has_control(value):
        _invalid()
    # Match is literal by design. Reject regex syntax rather than silently
    # treating a caller's likely regex as ordinary text.
    if any(character in value for character in "\\^$*?{}()|[]"):
        _invalid()
    return value


def _rule_id(value: Any) -> str:
    if not isinstance(value, str) or not value.strip() or len(value) > MAX_RULE_ID_LENGTH or _has_control(value):
        _invalid()
    return value


def _is_han(value: str) -> bool:
    return all("\u3400" <= character <= "\u4dbf" or "\u4e00" <= character <= "\u9fff" or "\uf900" <= character <= "\ufaff" for character in value)


def _validate_rule(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict):
        _invalid()
    kind = value.get("kind")
    if kind == "spoken_form":
        if set(value) != {"id", "match", "kind", "value"}:
            _invalid()
        spoken = value.get("value")
        if (
            not isinstance(spoken, str)
            or not 1 <= len(spoken) <= MAX_SPOKEN_FORM_LENGTH
            or _has_control(spoken)
            or "[" in spoken
            or "]" in spoken
        ):
            _invalid()
        return {"id": _rule_id(value.get("id")), "match": _literal_match(value.get("match")), "kind": kind, "value": spoken}
    if kind == "bopomofo":
        if set(value) != {"id", "match", "kind", "readings"}:
            _invalid()
        match = _literal_match(value.get("match"))
        readings = value.get("readings")
        if not _is_han(match) or not isinstance(readings, list) or len(readings) != len(match) or not readings:
            _invalid()
        if any(not isinstance(reading, str) or not BOPOMOFO_READING.fullmatch(reading) for reading in readings):
            _invalid()
        return {"id": _rule_id(value.get("id")), "match": match, "kind": kind, "readings": readings}
    _invalid()


def _validate_layer(value: Any, maximum: int) -> list[dict[str, Any]]:
    if not isinstance(value, list) or len(value) > maximum:
        _invalid()
    rules = [_validate_rule(rule) for rule in value]
    if len({rule["match"] for rule in rules}) != len(rules) or len({rule["id"] for rule in rules}) != len(rules):
        _invalid()
    return rules


def validate_pronunciation(value: Any) -> dict[str, list[dict[str, Any]]]:
    if not isinstance(value, dict) or set(value) - {"character_overrides", "request_overrides"}:
        _invalid()
    character = _validate_layer(value.get("character_overrides", []), MAX_EXTERNAL_RULES)
    request = _validate_layer(value.get("request_overrides", []), MAX_EXTERNAL_RULES)
    if len(character) + len(request) > MAX_EXTERNAL_RULES:
        _invalid()
    if len({rule["id"] for rule in character + request}) != len(character) + len(request):
        _invalid()
    return {"character_overrides": character, "request_overrides": request}


def validate_global_rules(value: Any, *, require_schema: bool = False) -> dict[str, Any]:
    expected = {"revision", "rules"}
    if require_schema:
        expected.add("schema_version")
    if not isinstance(value, dict) or set(value) != expected:
        _invalid()
    if require_schema and value.get("schema_version") != "breezy_pronunciation_rules_v1":
        _invalid()
    revision = value.get("revision")
    if isinstance(revision, bool) or not isinstance(revision, int) or revision < 1:
        _invalid()
    rules = _validate_layer(value.get("rules"), MAX_GLOBAL_RULES)
    return {"revision": revision, "rules": rules}


def load_global_rules(path: Path | None = None) -> dict[str, Any]:
    source = path or Path(__file__).with_name("pronunciation-rules.json")
    try:
        payload = json.loads(source.read_text(encoding="utf-8"))
        return validate_global_rules(payload, require_schema=True)
    except (OSError, UnicodeDecodeError, json.JSONDecodeError, PronunciationError) as error:
        raise RuntimeError("global_pronunciation_rules_invalid") from error


def _effective_rules(global_rules: dict[str, Any], external: dict[str, list[dict[str, Any]]]) -> list[list[dict[str, Any]]]:
    layers = [global_rules["rules"], external["character_overrides"], external["request_overrides"]]
    selected: dict[str, dict[str, Any]] = {}
    for rules in reversed(layers):
        for rule in rules:
            selected.setdefault(rule["match"], rule)
    return [[rule for rule in rules if selected.get(rule["match"]) is rule] for rules in layers]


def _apply_rules(text: str, rules: list[list[dict[str, Any]]], kind: str) -> tuple[str, set[str]]:
    output: list[str] = []
    applied: set[str] = set()
    cursor = 0
    while cursor < len(text):
        matching: list[tuple[int, int, dict[str, Any]]] = []
        for priority, layer in enumerate(rules):
            for rule in layer:
                if rule["kind"] == kind and text.startswith(rule["match"], cursor):
                    matching.append((priority, len(rule["match"]), rule))
        if not matching:
            output.append(text[cursor])
            cursor += 1
            continue
        priority, _length, rule = max(matching, key=lambda item: (item[0], item[1]))
        del priority
        match = rule["match"]
        if kind == "spoken_form":
            output.append(rule["value"])
        else:
            output.extend(character + f"[:{reading}]" for character, reading in zip(match, rule["readings"], strict=True))
        applied.add(rule["id"])
        cursor += len(match)
    return "".join(output), applied


def compile_pronunciation(
    text: str,
    value: Any,
    *,
    normalizer: Callable[[str], str],
    global_rules: dict[str, Any] | None = None,
) -> dict[str, Any]:
    external = validate_pronunciation(value)
    global_data = validate_global_rules(global_rules, require_schema=False) if global_rules is not None else load_global_rules()
    layers = _effective_rules(global_data, external)
    spoken_text, spoken_applied = _apply_rules(text, layers, "spoken_form")
    normalized = normalizer(spoken_text)
    if not isinstance(normalized, str) or not normalized or len(normalized) > MAX_MODEL_TEXT_LENGTH:
        raise RuntimeError("pronunciation_compile_failed")
    model_text, bopomofo_applied = _apply_rules(normalized, layers, "bopomofo")
    if len(model_text) > MAX_MODEL_TEXT_LENGTH:
        raise RuntimeError("pronunciation_compile_failed")
    applied = spoken_applied | bopomofo_applied
    return {
        "rule_revision": global_data["revision"],
        "spoken_text": spoken_text,
        "model_text": model_text,
        "applied_rule_ids": [rule["id"] for layer in layers for rule in layer if rule["id"] in applied],
        "characters": {"source": len(text), "spoken": len(spoken_text), "model": len(model_text)},
    }
