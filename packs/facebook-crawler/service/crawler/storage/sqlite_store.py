"""SQLite post store with content/url dedupe."""

from __future__ import annotations

import hashlib
import json
import sqlite3
from pathlib import Path
from typing import Iterable


SCHEMA = """
CREATE TABLE IF NOT EXISTS posts (
    dedupe_key TEXT PRIMARY KEY,
    post_url TEXT,
    source_url TEXT,
    content_hash TEXT NOT NULL,
    content TEXT,
    discovery_mode TEXT,
    discovery_query TEXT,
    first_seen_run_id TEXT,
    first_seen_at TEXT,
    last_seen_run_id TEXT,
    last_seen_at TEXT,
    seen_count INTEGER NOT NULL DEFAULT 1,
    payload_json TEXT
);

CREATE INDEX IF NOT EXISTS idx_posts_post_url ON posts(post_url);
CREATE INDEX IF NOT EXISTS idx_posts_source_url ON posts(source_url);
CREATE INDEX IF NOT EXISTS idx_posts_discovery ON posts(discovery_mode, discovery_query);
"""


class PostStore:
    def __init__(self, db_path: Path | str) -> None:
        self.db_path = Path(db_path)
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        self._conn = sqlite3.connect(str(self.db_path))
        self._conn.row_factory = sqlite3.Row
        self._conn.executescript(SCHEMA)
        self._conn.commit()

    def close(self) -> None:
        self._conn.close()

    def __enter__(self) -> "PostStore":
        return self

    def __exit__(self, *args) -> None:
        self.close()

    @staticmethod
    def content_hash(content: str) -> str:
        normalized = " ".join((content or "").split())
        return hashlib.sha256(normalized.encode("utf-8")).hexdigest()

    @classmethod
    def dedupe_key(cls, record: dict) -> str:
        post_url = (record.get("post_url") or "").strip()
        source_url = (record.get("source_url") or "").strip()
        content = record.get("content") or ""
        digest = cls.content_hash(str(content))
        if post_url and post_url != source_url:
            return f"url:{post_url}"
        return f"src:{source_url}|hash:{digest}"

    def upsert_records(self, records: Iterable[dict], *, run_id: str) -> tuple[int, int]:
        new_count = 0
        dup_count = 0
        for record in records:
            content = record.get("content") or ""
            if not str(content).strip():
                continue
            key = self.dedupe_key(record)
            now = record.get("fetched_at") or ""
            payload = json.dumps(record, ensure_ascii=False)
            existing = self._conn.execute(
                "SELECT seen_count FROM posts WHERE dedupe_key = ?", (key,)
            ).fetchone()
            if existing is None:
                self._conn.execute(
                    """
                    INSERT INTO posts (
                        dedupe_key, post_url, source_url, content_hash, content,
                        discovery_mode, discovery_query,
                        first_seen_run_id, first_seen_at, last_seen_run_id, last_seen_at,
                        seen_count, payload_json
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                    """,
                    (
                        key,
                        record.get("post_url") or "",
                        record.get("source_url") or "",
                        self.content_hash(str(content)),
                        str(content),
                        record.get("discovery_mode") or "",
                        record.get("discovery_query") or "",
                        run_id,
                        now,
                        run_id,
                        now,
                        payload,
                    ),
                )
                new_count += 1
            else:
                self._conn.execute(
                    """
                    UPDATE posts
                    SET last_seen_run_id = ?, last_seen_at = ?,
                        seen_count = seen_count + 1, payload_json = ?
                    WHERE dedupe_key = ?
                    """,
                    (run_id, now, payload, key),
                )
                dup_count += 1
        self._conn.commit()
        return new_count, dup_count

    def count(self) -> int:
        row = self._conn.execute("SELECT COUNT(*) AS c FROM posts").fetchone()
        return int(row["c"]) if row else 0
