from __future__ import annotations

import pytest

import pronunciation


def test_versioned_global_rules_file_is_valid() -> None:
    assert pronunciation.load_global_rules() == {
        "revision": 1,
        "rules": [{
            "id": "global:kn",
            "match": "K&N 204-1",
            "kind": "spoken_form",
            "value": "K and N 二零四之一",
        }],
    }


def test_compiles_layered_spoken_forms_and_bopomofo_after_normalization() -> None:
    compiled = pronunciation.compile_pronunciation(
        "AI 協助檢查 K&N 204-1 濾心。",
        {
            "character_overrides": [{
                "id": "character:axian:ai",
                "match": "AI",
                "kind": "spoken_form",
                "value": "欸哀",
            }],
            "request_overrides": [{
                "id": "podcast:49:filter",
                "match": "濾心",
                "kind": "bopomofo",
                "readings": ["ㄌㄩ4", "ㄒㄧㄣ1"],
            }],
        },
        normalizer=lambda value: value.replace("欸哀 ", "欸哀"),
        global_rules={
            "revision": 1,
            "rules": [{
                "id": "global:kn",
                "match": "K&N 204-1",
                "kind": "spoken_form",
                "value": "K and N 二零四之一",
            }],
        },
    )

    assert compiled == {
        "rule_revision": 1,
        "spoken_text": "欸哀 協助檢查 K and N 二零四之一 濾心。",
        "model_text": "欸哀協助檢查 K and N 二零四之一 濾[:ㄌㄩ4]心[:ㄒㄧㄣ1]。",
        "applied_rule_ids": ["global:kn", "character:axian:ai", "podcast:49:filter"],
        "characters": {"source": 21, "spoken": 25, "model": 37},
    }


def test_higher_layer_wins_at_same_offset_and_same_layer_uses_longest_literal() -> None:
    compiled = pronunciation.compile_pronunciation(
        "ABC",
        {
            "character_overrides": [{
                "id": "character:short",
                "match": "A",
                "kind": "spoken_form",
                "value": "甲",
            }],
            "request_overrides": [{
                "id": "request:long",
                "match": "AB",
                "kind": "spoken_form",
                "value": "乙",
            }, {
                "id": "request:short",
                "match": "A",
                "kind": "spoken_form",
                "value": "丙",
            }],
        },
        normalizer=lambda value: value,
        global_rules={"revision": 1, "rules": [{
            "id": "global:longest",
            "match": "ABC",
            "kind": "spoken_form",
            "value": "丁",
        }]},
    )

    assert compiled["spoken_text"] == "乙C"
    assert compiled["applied_rule_ids"] == ["request:long"]


def test_replacement_is_not_recursively_matched() -> None:
    compiled = pronunciation.compile_pronunciation(
        "AI",
        {"request_overrides": [{
            "id": "request:ai",
            "match": "AI",
            "kind": "spoken_form",
            "value": "人工智慧",
        }, {
            "id": "request:human",
            "match": "人工智慧",
            "kind": "spoken_form",
            "value": "不得出現",
        }]},
        normalizer=lambda value: value,
        global_rules={"revision": 1, "rules": []},
    )

    assert compiled["spoken_text"] == "人工智慧"
    assert compiled["applied_rule_ids"] == ["request:ai"]


@pytest.mark.parametrize(
    "payload",
    [
        {"request_overrides": [{"id": "x", "match": "A", "kind": "spoken_form", "value": "[:ㄞ1]"}]},
        {"request_overrides": [{"id": "x", "match": "^AI$", "kind": "spoken_form", "value": "欸哀"}]},
        {"request_overrides": [{"id": "x", "match": "[:ㄞ1]", "kind": "spoken_form", "value": "欸哀"}]},
        {"request_overrides": [{"id": "x", "match": "濾心", "kind": "bopomofo", "readings": ["ㄌㄩ4"]}]},
        {"request_overrides": [{"id": "x", "match": "A", "kind": "bopomofo", "readings": ["ㄞ1"]}]},
    ],
)
def test_rejects_unsafe_or_invalid_external_rules(payload: dict[str, object]) -> None:
    with pytest.raises(pronunciation.PronunciationError, match="^invalid_pronunciation_rules$"):
        pronunciation.validate_pronunciation(payload)
