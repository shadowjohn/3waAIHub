<?php
declare(strict_types=1);

function hub_breezy_pronunciation_invalid(): never
{
    throw new InvalidArgumentException('invalid_pronunciation_rules');
}

function hub_breezy_pronunciation_length(string $value): int
{
    $count = preg_match_all('/./us', $value);
    if ($count === false) {
        hub_breezy_pronunciation_invalid();
    }

    return $count;
}

function hub_breezy_pronunciation_string(mixed $value, int $minimum, int $maximum): string
{
    if (!is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        hub_breezy_pronunciation_invalid();
    }
    $length = hub_breezy_pronunciation_length($value);
    if ($length < $minimum || $length > $maximum) {
        hub_breezy_pronunciation_invalid();
    }

    return $value;
}

function hub_breezy_pronunciation_literal_match(mixed $value): string
{
    $match = hub_breezy_pronunciation_string($value, 1, 80);
    if (strpbrk($match, '\\^$*?{}()|[]') !== false) {
        hub_breezy_pronunciation_invalid();
    }

    return $match;
}

function hub_breezy_pronunciation_rule(mixed $value): array
{
    if (!is_array($value) || array_is_list($value)) {
        hub_breezy_pronunciation_invalid();
    }
    $kind = $value['kind'] ?? null;
    if ($kind === 'spoken_form') {
        if (count($value) !== 4 || array_diff(array_keys($value), ['id', 'match', 'kind', 'value']) !== []) {
            hub_breezy_pronunciation_invalid();
        }
        $spoken = hub_breezy_pronunciation_string($value['value'] ?? null, 1, 160);
        if (str_contains($spoken, '[') || str_contains($spoken, ']')) {
            hub_breezy_pronunciation_invalid();
        }

        return [
            'id' => hub_breezy_pronunciation_string($value['id'] ?? null, 1, 128),
            'match' => hub_breezy_pronunciation_literal_match($value['match'] ?? null),
            'kind' => 'spoken_form',
            'value' => $spoken,
        ];
    }
    if ($kind !== 'bopomofo' || count($value) !== 4 || array_diff(array_keys($value), ['id', 'match', 'kind', 'readings']) !== []) {
        hub_breezy_pronunciation_invalid();
    }
    $match = hub_breezy_pronunciation_literal_match($value['match'] ?? null);
    $readings = $value['readings'] ?? null;
    if (!is_array($readings) || !array_is_list($readings) || count($readings) !== hub_breezy_pronunciation_length($match)
        || preg_match('/\A[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]+\z/u', $match) !== 1) {
        hub_breezy_pronunciation_invalid();
    }
    foreach ($readings as $reading) {
        if (!is_string($reading) || preg_match('/\A[ㄅㄆㄇㄈㄉㄊㄋㄌㄍㄎㄏㄐㄑㄒㄓㄔㄕㄖㄗㄘㄙㄚㄛㄜㄝㄞㄟㄠㄡㄢㄣㄤㄥㄦㄧㄨㄩ˙]+[1-5]\z/u', $reading) !== 1) {
            hub_breezy_pronunciation_invalid();
        }
    }

    return [
        'id' => hub_breezy_pronunciation_string($value['id'] ?? null, 1, 128),
        'match' => $match,
        'kind' => 'bopomofo',
        'readings' => $readings,
    ];
}

function hub_breezy_pronunciation_layer(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
        hub_breezy_pronunciation_invalid();
    }
    $rules = array_map('hub_breezy_pronunciation_rule', $value);
    $matches = array_column($rules, 'match');
    $ids = array_column($rules, 'id');
    if (count($matches) !== count(array_unique($matches)) || count($ids) !== count(array_unique($ids))) {
        hub_breezy_pronunciation_invalid();
    }

    return $rules;
}

function hub_breezy_pronunciation_validate_input(mixed $value): array
{
    if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['character_overrides', 'request_overrides']) !== []) {
        hub_breezy_pronunciation_invalid();
    }
    $character = hub_breezy_pronunciation_layer($value['character_overrides'] ?? []);
    $request = hub_breezy_pronunciation_layer($value['request_overrides'] ?? []);
    if (count($character) + count($request) > 50) {
        hub_breezy_pronunciation_invalid();
    }
    $ids = array_column([...$character, ...$request], 'id');
    if (count($ids) !== count(array_unique($ids))) {
        hub_breezy_pronunciation_invalid();
    }

    return ['character_overrides' => $character, 'request_overrides' => $request];
}
