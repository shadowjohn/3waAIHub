"""Deterministic, offline compatibility reflow for legacy subtitles."""

from __future__ import annotations

import re
from pathlib import Path
from typing import Any, Callable

from offline_paths import CKIP_MODEL_DIR

_CKIP_SEGMENTER: Any | None = None
_CJK_RE = re.compile(r"[\u4e00-\u9fff]")
_PUNCT_RE = re.compile(r"^[，。！？；：、,.!?;:()\[\]{}<>【】《》「」『』]+$")
_TERMINAL_PUNCTUATION = "。！？.!?"
_CLAUSE_STARTERS = {"而", "但", "所以", "因為", "就是", "如果", "然後", "再", "又", "並且", "而且", "不過", "可是", "因此", "於是", "那"}
_SECONDARY_BREAKS = {"嗎", "呢", "吧", "啊", "呀", "不是", "所以", "但是", "如果"}
_LATIN_OVERRIDES = {
    "letslearntaiwaneseinenglish": "Let's learn Taiwanese in English",
    "inenglish": "in English",
    "howdoyoudo": "how do you do",
    "youtalkingabout": "you talking about",
    "talkingabout": "talking about",
    "youdo": "you do",
    "whatsup": "what's up",
    "goaway": "go away",
}
_LATIN_CORRECTIONS = (
    (r"\blets\b", "let's"), (r"\bwhats\b", "what's"), (r"\bim\b", "I'm"),
    (r"\bcant\b", "can't"), (r"\bwont\b", "won't"), (r"\bdont\b", "don't"),
    (r"\bdoesnt\b", "doesn't"), (r"\bdidnt\b", "didn't"), (r"\bisnt\b", "isn't"),
    (r"\bive\b", "I've"), (r"\byoure\b", "you're"), (r"\btheyre\b", "they're"),
    (r"\bwere\b", "we're"),
)


def _number(value: object) -> float | None:
    if isinstance(value, bool):
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _is_chinese(language: str, text: str) -> bool:
    return language.lower() in {"zh", "nan"} or (language.lower() == "auto" and bool(_CJK_RE.search(text)))


def _normalize_text(text: str) -> str:
    text = re.sub(r"\s+", " ", text).strip()
    text = re.sub(r"(?<=[A-Za-z0-9])\s*[，,]\s*(?=[A-Za-z0-9])", ", ", text)
    text = re.sub(r"(?<=[\u4e00-\u9fff])\s*[，,]\s*(?=[\u4e00-\u9fffA-Za-z0-9])", "，", text)
    text = re.sub(r"(?<=[A-Za-z0-9\u4e00-\u9fff])\s*[，,]\s*(?=[\u4e00-\u9fff])", "，", text)
    text = re.sub(r"\s+([，。！？；：、,.!?;:)\]}>】》」』])", r"\1", text)
    text = re.sub(r"([([{<【《「『])\s+", r"\1", text)
    for pattern, replacement in _LATIN_CORRECTIONS:
        text = re.sub(pattern, replacement, text, flags=re.IGNORECASE)
    return text.strip()


def _restore_latin_spaces(text: str) -> str:
    normalized = text.replace("’", "'").lower()
    if normalized in _LATIN_OVERRIDES:
        return _LATIN_OVERRIDES[normalized]
    if len(normalized) >= 8 and re.fullmatch(r"[a-z0-9']+", normalized):
        try:
            from wordsegment import load, segment

            load()
            pieces = segment(normalized)
            if len(pieces) > 1 and "".join(pieces) == normalized:
                return " ".join(pieces)
        except Exception:
            pass
    return text


def _is_latin(text: str) -> bool:
    return bool(re.fullmatch(r"[A-Za-z0-9]+(?:['’.-][A-Za-z0-9]+)*", text))


def _is_punctuation(text: str) -> bool:
    return bool(_PUNCT_RE.fullmatch(text))


def _smart_join(words: list[dict[str, Any]], punctuation: dict[int, str] | None = None) -> str:
    pieces: list[tuple[str, str]] = []
    for index, word in enumerate(words):
        text = str(word.get("word", "")).strip()
        if text:
            if _is_latin(text):
                text = _restore_latin_spaces(text)
                kind = "latin"
            elif _is_punctuation(text):
                kind = "punct"
            elif _CJK_RE.search(text):
                kind = "cjk"
            else:
                kind = "other"
            pieces.append((text, kind))
        if punctuation and punctuation.get(index):
            pieces.append((punctuation[index], "punct"))

    result = ""
    previous = ""
    for text, kind in pieces:
        if result and kind != "punct" and previous != "punct":
            if kind == "latin" or previous == "latin":
                result += " "
        result += text
        previous = kind
    return _normalize_text(result)


def _opencc(text: str) -> str:
    try:
        from opencc import OpenCC

        return OpenCC("s2twp").convert(text)
    except Exception:
        return text


def _free_vram_mb() -> int:
    try:
        import torch

        return int(torch.cuda.mem_get_info()[0] // (1024 * 1024)) if torch.cuda.is_available() else 0
    except Exception:
        return 0


def _ckip_tokens(text: str, model_dir: Path) -> list[str]:
    global _CKIP_SEGMENTER
    if _CKIP_SEGMENTER is None:
        from ckip_transformers.nlp import CkipWordSegmenter

        # ponytail: one process cache; add eviction only if workers multiplex incompatible CKIP models.
        _CKIP_SEGMENTER = CkipWordSegmenter(model_name=str(model_dir), device=0)
    rows = _CKIP_SEGMENTER([text])
    return [str(token) for token in (rows[0] if rows else [])]


def _jieba_tokens(text: str) -> list[str]:
    import jieba

    return [str(token) for token in jieba.lcut(text)]


def _breaker_tokens(
    text: str,
    free_vram_mb: int | None,
    ckip_model_dir: Path,
    ckip_segment: Callable[[str], list[str]] | None,
    jieba_segment: Callable[[str], list[str]] | None,
) -> tuple[list[str], dict[str, str | None]]:
    available = _free_vram_mb() if free_vram_mb is None else free_vram_mb
    ckip_error = None
    if available >= 4 * 1024:
        try:
            tokens = (ckip_segment or (lambda value: _ckip_tokens(value, ckip_model_dir)))(text)
            return [str(token) for token in tokens], {"subtitle_breaker": "ckip", "ckip_error": None}
        except Exception as error:
            ckip_error = str(error)
    try:
        tokens = (jieba_segment or _jieba_tokens)(text)
    except Exception:
        return _fallback_tokens(text), {"subtitle_breaker": "fallback", "ckip_error": ckip_error}
    return [str(token) for token in tokens], {"subtitle_breaker": "jieba", "ckip_error": ckip_error}


def _compact(text: str) -> str:
    return "".join(character for character in text if character.isalnum() or "\u4e00" <= character <= "\u9fff")


def _fallback_tokens(text: str) -> list[str]:
    return re.findall(r"[A-Za-z0-9]+(?:['’.-][A-Za-z0-9]+)*|[\u4e00-\u9fff]+|[^\s]", text)


def _word_spans(words: list[dict[str, Any]]) -> list[tuple[int, int]]:
    position = 0
    spans = []
    for word in words:
        length = len(_compact(str(word.get("word", "")).strip()))
        spans.append((position, position + length))
        position += length
    return spans


def _punctuation_and_boundaries(words: list[dict[str, Any]], tokens: list[str]) -> tuple[dict[int, str], set[int]]:
    spans = _word_spans(words)
    word_compact = "".join(_compact(str(word.get("word", "")).strip()) for word in words)
    token_compact = "".join(_compact(token) for token in tokens)
    if not word_compact or token_compact.lower() != word_compact.lower():
        tokens = _fallback_tokens(_smart_join(words))
    punctuation: dict[int, str] = {}
    boundaries: set[int] = set()
    position = 0
    last_word = 0
    for token in tokens:
        compact = _compact(token)
        if not compact:
            if _is_punctuation(token):
                carried = re.search(r"[，。！？；：、,.!?;:()\[\]{}<>【】《》「」『』]+$", str(words[last_word].get("word", "")).strip())
                if carried is None or token not in carried.group(0):
                    punctuation[last_word] = punctuation.get(last_word, "") + token
            continue
        end = position + len(compact)
        for index, (_, word_end) in enumerate(spans):
            if word_end <= end:
                last_word = index
                if word_end == end:
                    boundaries.add(index)
            else:
                break
        position = end
    return punctuation, boundaries


def _adjust_boundary(index: int, start: int, boundaries: set[int]) -> int:
    choices = [candidate for candidate in boundaries if start < candidate <= index]
    return min(choices, key=lambda candidate: (abs(candidate - index), candidate > index)) if choices else index


def _copy_words(segment: dict[str, Any], chinese: bool) -> list[dict[str, Any]] | None:
    raw_words = segment.get("words")
    if not isinstance(raw_words, list) or not raw_words:
        return None
    words = []
    for item in raw_words:
        if not isinstance(item, dict) or not str(item.get("word", "")).strip() or _number(item.get("start")) is None or _number(item.get("end")) is None:
            return None
        copied = dict(item)
        if chinese:
            copied["word"] = _opencc(str(copied["word"]))
        words.append(copied)
    return words


def _speaker_groups(words: list[dict[str, Any]], default: object) -> list[tuple[object, list[dict[str, Any]]]]:
    groups: list[tuple[object, list[dict[str, Any]]]] = []
    for word in words:
        speaker = word.get("speaker", default)
        if groups and groups[-1][0] == speaker:
            groups[-1][1].append(word)
        else:
            groups.append((speaker, [word]))
    return groups


def _reflow_group(
    segment: dict[str, Any],
    words: list[dict[str, Any]],
    speaker: object,
    chinese: bool,
    tokens: list[str],
) -> list[dict[str, Any]]:
    punctuation, boundaries = _punctuation_and_boundaries(words, tokens)
    cues: list[dict[str, Any]] = []
    start = 0
    last_candidate = -1
    for index, word in enumerate(words):
        word_text = str(word.get("word", "")).strip()
        next_word = words[index + 1] if index + 1 < len(words) else None
        end = _number(word.get("end"))
        if end is None:
            end = _number(word.get("start"))
        if end is None:
            end = 0.0
        next_start = _number(next_word.get("start")) if next_word else end
        rendered = _smart_join(words[start:index + 1], {key - start: value for key, value in punctuation.items() if start <= key <= index})
        hard_break = any(mark in punctuation.get(index, "") or mark in word_text for mark in _TERMINAL_PUNCTUATION)
        if punctuation.get(index) or (next_start is not None and next_start - end >= 0.35) or (next_word and str(next_word.get("word", "")).strip() in _CLAUSE_STARTERS) or word_text in _SECONDARY_BREAKS:
            last_candidate = index
        cue_start = _number(words[start].get("start"))
        duration = end - (cue_start if cue_start is not None else end)
        should_break = bool(next_word) and (hard_break or len(rendered) >= 28 or duration >= 6.0)
        if not should_break:
            continue
        cut = index if hard_break else (last_candidate if last_candidate >= start else max(start, index - 1))
        if chinese:
            cut = _adjust_boundary(cut, start, boundaries)
        cue_words = words[start:cut + 1]
        if not cue_words:
            continue
        cue_punctuation = {key - start: value for key, value in punctuation.items() if start <= key <= cut}
        text = _smart_join(cue_words, cue_punctuation)
        if chinese and text and text[-1] not in _TERMINAL_PUNCTUATION:
            text += "。"
        cue: dict[str, Any] = {
            "start": _number(cue_words[0].get("start")) or 0.0,
            "end": _number(cue_words[-1].get("end")) if _number(cue_words[-1].get("end")) is not None else (_number(cue_words[-1].get("start")) or 0.0),
            "text": text,
            "words": cue_words,
        }
        if speaker is not None:
            cue["speaker"] = speaker
        cues.append(cue)
        start = cut + 1
        last_candidate = -1
    if start < len(words):
        cue_words = words[start:]
        cue_punctuation = {key - start: value for key, value in punctuation.items() if key >= start}
        text = _smart_join(cue_words, cue_punctuation)
        if chinese and text and text[-1] not in _TERMINAL_PUNCTUATION:
            text += "。"
        cue = {
            "start": _number(cue_words[0].get("start")) or 0.0,
            "end": _number(cue_words[-1].get("end")) if _number(cue_words[-1].get("end")) is not None else (_number(cue_words[-1].get("start")) or 0.0),
            "text": text,
            "words": cue_words,
        }
        if speaker is not None:
            cue["speaker"] = speaker
        cues.append(cue)
    return cues


def reflow_legacy_segments(
    segments: list[dict[str, Any]],
    language: str = "auto",
    ckip_model_dir: str | Path = CKIP_MODEL_DIR,
    *,
    free_vram_mb: int | None = None,
    ckip_segment: Callable[[str], list[str]] | None = None,
    jieba_segment: Callable[[str], list[str]] | None = None,
) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    """Return reflowed cues and the actual CJK breaker used, without I/O or network."""
    result: list[dict[str, Any]] = []
    diagnostic: dict[str, Any] = {"subtitle_breaker": None, "ckip_error": None}
    breakers: list[str] = []
    model_dir = Path(ckip_model_dir)
    for segment in segments:
        copied = dict(segment)
        text = str(copied.get("text", ""))
        chinese = _is_chinese(language, text)
        words = _copy_words(copied, chinese)
        if not words:
            result.append(copied)
            continue
        if chinese:
            copied["text"] = _opencc(text)
        groups = _speaker_groups(words, copied.get("speaker"))
        for speaker, group_words in groups:
            group_text = str(copied["text"]) if len(groups) == 1 else _smart_join(group_words)
            if chinese:
                tokens, used = _breaker_tokens(group_text, free_vram_mb, model_dir, ckip_segment, jieba_segment)
                breaker = used.get("subtitle_breaker")
                if isinstance(breaker, str) and breaker not in breakers:
                    breakers.append(breaker)
                if used.get("ckip_error") is not None:
                    diagnostic["ckip_error"] = used["ckip_error"]
            else:
                tokens = _fallback_tokens(group_text)
            result.extend(_reflow_group(copied, group_words, speaker, chinese, tokens))
    if len(breakers) == 1:
        diagnostic["subtitle_breaker"] = breakers[0]
    elif len(breakers) > 1:
        diagnostic["subtitle_breaker"] = "mixed"
        diagnostic["subtitle_breakers"] = breakers
    return result, diagnostic
