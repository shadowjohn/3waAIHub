from long_form import canonical_json, make_plan


def main() -> int:
    text = "Dr. Lin 說：「8,500 rpm 時 RC Valve 間隙是 0.7 mm。」"
    first = make_plan(text, 42, "derived_per_chunk", 42)
    second = make_plan(text, 42, "derived_per_chunk", 42)
    assert canonical_json(first) == canonical_json(second)
    assert "8,500 rpm" in first["normalized_input"] and "0.7 mm" in first["normalized_input"]
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
