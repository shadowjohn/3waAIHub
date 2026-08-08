"""Keyword loading and filtering."""

from __future__ import annotations

from pathlib import Path

from crawler.extract.posts import match_keywords


def load_keywords(path: str | Path) -> list[str]:
    path = Path(path)
    if not path.exists():
        return []
    out: list[str] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        out.append(line)
    return out


def filter_records(records: list[dict], keywords: list[str]) -> list[dict]:
    if not keywords:
        return list(records)
    filtered: list[dict] = []
    for rec in records:
        hits = match_keywords(str(rec.get("content") or ""), keywords)
        if hits:
            row = dict(rec)
            row["matched_keywords"] = hits
            filtered.append(row)
    return filtered
