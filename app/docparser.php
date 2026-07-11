<?php
declare(strict_types=1);

function hub_docparser_build_docir(array $structurePayload, array $options): array
{
    $pages = [];
    foreach ($structurePayload['pages'] ?? [] as $page) {
        if (!is_array($page)) {
            continue;
        }
        $pageNo = hub_docparser_page_number($page, count($pages) + 1);
        $pages[] = [
            'page' => $pageNo,
            'width' => (float) ($page['width'] ?? 0),
            'height' => (float) ($page['height'] ?? 0),
        ];
    }
    if ($pages === []) {
        $pages[] = ['page' => 1, 'width' => 0.0, 'height' => 0.0];
    }

    $blocks = [];
    foreach ($structurePayload['blocks'] ?? [] as $index => $block) {
        if (!is_array($block)) {
            continue;
        }
        $page = hub_docparser_page_number($block, 1);
        $order = max(1, (int) ($block['order'] ?? $index + 1));
        $type = hub_docparser_block_type($block);
        $bbox = hub_docparser_normalize_bbox($block['bbox'] ?? $block['poly'] ?? null);
        $rawId = (string) ($block['id'] ?? ('raw-' . ($index + 1)));
        $blocks[] = [
            'id' => 'p' . $page . '-b' . $order,
            'page' => $page,
            'order' => $order,
            'type' => $type,
            'bbox' => $bbox,
            'source_text' => hub_docparser_block_text($block),
            'section_path' => hub_docparser_section_path($type, hub_docparser_block_text($block)),
            'provenance' => [
                'engine' => 'structure-main',
                'source_block_id' => $rawId,
            ],
        ];
    }

    usort($blocks, static fn(array $a, array $b): int => [
        $a['page'],
        $a['order'],
        (float) ($a['bbox'][1] ?? 0.0),
        (float) ($a['bbox'][0] ?? 0.0),
    ] <=> [
        $b['page'],
        $b['order'],
        (float) ($b['bbox'][1] ?? 0.0),
        (float) ($b['bbox'][0] ?? 0.0),
    ]);

    $pageOrders = [];
    foreach ($blocks as &$block) {
        $pageOrders[$block['page']] = ($pageOrders[$block['page']] ?? 0) + 1;
        $block['order'] = $pageOrders[$block['page']];
        $block['id'] = 'p' . $block['page'] . '-b' . $block['order'];
    }
    unset($block);

    $knownPages = [];
    foreach ($pages as $page) {
        $knownPages[(int) $page['page']] = true;
    }
    foreach ($blocks as $block) {
        $pageNo = (int) ($block['page'] ?? 0);
        if ($pageNo > 0 && !isset($knownPages[$pageNo])) {
            $pages[] = ['page' => $pageNo, 'width' => 0.0, 'height' => 0.0];
            $knownPages[$pageNo] = true;
        }
    }
    usort($pages, static fn(array $a, array $b): int => (int) ($a['page'] ?? 0) <=> (int) ($b['page'] ?? 0));

    return [
        'schema' => '3wa-docir-v0.1',
        'profile' => (string) ($options['profile'] ?? 'technical_manual'),
        'target_language' => (string) ($options['target_language'] ?? 'zh-TW'),
        'source_kind' => (string) ($structurePayload['source_kind'] ?? 'structure_blocks'),
        'structure_mock' => !empty($structurePayload['mock']),
        'pages' => $pages,
        'blocks' => $blocks,
        'figures' => is_array($structurePayload['figures'] ?? null) ? $structurePayload['figures'] : [],
    ];
}

function hub_docparser_section_path(string $type, string $text): array
{
    return $type === 'heading' && trim($text) !== '' ? [trim($text)] : [];
}

function hub_docparser_structure_payload(array $payload): array
{
    if (isset($payload['document_json']) && is_array($payload['document_json'])) {
        $structured = hub_docparser_structure_from_document_json($payload['document_json']);
        if (($structured['blocks'] ?? []) !== []) {
            $structured['mock'] = !empty($payload['mock']);
            return $structured;
        }
    }

    return [
        'pages' => [['page' => 1, 'width' => 0, 'height' => 0]],
        'blocks' => [[
            'id' => 'raw-1',
            'page' => 1,
            'order' => 1,
            'type' => 'paragraph',
            'text' => (string)($payload['markdown'] ?? ''),
            'bbox' => [0, 0, 0, 0],
        ]],
        'figures' => [],
        'source_kind' => 'fallback_markdown',
        'mock' => !empty($payload['mock']),
    ];
}

function hub_docparser_structure_from_document_json(array $document): array
{
    $blocks = [];
    $pages = [];
    $documents = hub_docparser_document_pages($document);

    foreach ($documents as $pageIndex => $pagePayload) {
        if (!is_array($pagePayload)) {
            continue;
        }
        $pageNo = hub_docparser_page_number($pagePayload, $pageIndex + 1);
        $pageBlocks = $pagePayload['blocks'] ?? $pagePayload['res'] ?? [];
        if (!is_array($pageBlocks)) {
            continue;
        }
        $pages[] = [
            'page' => $pageNo,
            'width' => (float)($pagePayload['width'] ?? 0),
            'height' => (float)($pagePayload['height'] ?? 0),
        ];
        foreach ($pageBlocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $blocks[] = [
                'id' => (string)($block['id'] ?? ('raw-' . $pageNo . '-' . ($index + 1))),
                'page' => hub_docparser_page_number($block, $pageNo),
                'order' => max(1, (int)($block['order'] ?? 1)),
                'type' => hub_docparser_block_type($block),
                'text' => hub_docparser_block_text($block),
                'bbox' => hub_docparser_normalize_bbox($block['bbox'] ?? $block['poly'] ?? null),
            ];
        }
    }

    usort($blocks, static fn(array $a, array $b): int => [
        $a['page'],
        $a['order'],
        (float) ($a['bbox'][1] ?? 0.0),
        (float) ($a['bbox'][0] ?? 0.0),
    ] <=> [
        $b['page'],
        $b['order'],
        (float) ($b['bbox'][1] ?? 0.0),
        (float) ($b['bbox'][0] ?? 0.0),
    ]);

    if ($pages === []) {
        $pages[] = ['page' => 1, 'width' => 0, 'height' => 0];
    }

    return [
        'pages' => $pages,
        'blocks' => $blocks,
        'figures' => [],
        'source_kind' => 'ppstructure_document_json',
    ];
}

function hub_docparser_document_pages(array $document): array
{
    if (isset($document['pages']) && is_array($document['pages'])) {
        return $document['pages'];
    }
    if (isset($document['document']) && is_array($document['document'])) {
        return $document['document'];
    }
    if (isset($document['blocks']) || isset($document['res'])) {
        return [$document];
    }
    if (hub_docparser_is_list($document)) {
        return $document;
    }

    return [];
}

function hub_docparser_is_list(array $value): bool
{
    if ($value === []) {
        return false;
    }

    return array_keys($value) === range(0, count($value) - 1);
}

function hub_docparser_block_type(array $block): string
{
    $type = strtolower(trim((string)($block['type'] ?? $block['block_type'] ?? $block['label'] ?? 'paragraph')));
    if (str_contains($type, 'title') || str_contains($type, 'heading') || $type === 'header') {
        return 'heading';
    }
    if (str_contains($type, 'table')) {
        return 'table';
    }
    if (str_contains($type, 'figure') || str_contains($type, 'image') || str_contains($type, 'photo')) {
        return 'figure';
    }
    if (str_contains($type, 'caption')) {
        return 'caption';
    }
    if (str_contains($type, 'list')) {
        return 'list';
    }

    return 'paragraph';
}

function hub_docparser_page_number(array $payload, int $fallback): int
{
    if (array_key_exists('page_index', $payload) && $payload['page_index'] !== null && $payload['page_index'] !== '') {
        return max(1, (int) $payload['page_index'] + 1);
    }
    foreach (['page', 'page_id'] as $key) {
        if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
            return max(1, (int) $payload[$key]);
        }
    }

    return max(1, $fallback);
}

function hub_docparser_normalize_bbox(mixed $bbox): array
{
    if (is_array($bbox) && count($bbox) >= 4 && !is_array($bbox[0] ?? null)) {
        return [
            (float) ($bbox[0] ?? 0.0),
            (float) ($bbox[1] ?? 0.0),
            (float) ($bbox[2] ?? 0.0),
            (float) ($bbox[3] ?? 0.0),
        ];
    }
    if (!is_array($bbox)) {
        return [0.0, 0.0, 0.0, 0.0];
    }

    $xs = [];
    $ys = [];
    foreach ($bbox as $point) {
        if (!is_array($point) || count($point) < 2) {
            continue;
        }
        $xs[] = (float) $point[0];
        $ys[] = (float) $point[1];
    }
    if ($xs === [] || $ys === []) {
        return [0.0, 0.0, 0.0, 0.0];
    }

    return [min($xs), min($ys), max($xs), max($ys)];
}

function hub_docparser_block_text(array $block): string
{
    foreach (['text', 'content', 'html', 'res_text'] as $key) {
        $value = trim((string) ($block[$key] ?? ''));
        if ($value !== '') {
            return strip_tags($value);
        }
    }

    return '';
}

function hub_docparser_translate_blocks(PDO $db, array $docir, array $input): array
{
    $required = (string)($input['translation_required'] ?? '1') !== '0';
    $target = (string)($input['target_language'] ?? 'zh-TW');
    if ($target === 'source') {
        return $docir;
    }

    $service = hub_get_service_by_mode($db, (string)($input['translate_mode'] ?? 'translate'));
    if (!$service || (int)($service['enabled'] ?? 0) !== 1) {
        if ($required) {
            throw new RuntimeException('blocked_dependency: translate service is unavailable.');
        }
        return $docir;
    }

    foreach ($docir['blocks'] as &$block) {
        $text = trim((string)($block['source_text'] ?? ''));
        if ($text === '' || !in_array((string)$block['type'], ['paragraph', 'heading', 'caption', 'list'], true)) {
            continue;
        }
        // ponytail: 先逐 block 翻譯，真的被延遲打到再補 batch。
        $block['translation'] = [
            'language' => $target,
            'text' => hub_docparser_translate_text($service, $text, $target),
            'source_block_id' => (string)($block['id'] ?? ''),
        ];
    }
    unset($block);

    return $docir;
}

function hub_docparser_translate_text(array $service, string $text, string $target): string
{
    $payload = json_encode([
        'text' => $text,
        'source_lang' => 'auto',
        'target_lang' => $target,
        'real_inference' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Cannot encode translation payload.');
    }

    $response = hub_docparser_post_json((string)$service['internal_url'], hub_service_gateway_timeout_sec($service), $payload);
    $body = json_decode((string)($response['body'] ?? ''), true);
    if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300 || !is_array($body) || empty($body['ok'])) {
        throw new RuntimeException('translation_failed');
    }

    return trim((string)($body['text'] ?? $body['translated_text'] ?? ''));
}

function hub_docparser_post_json(string $url, int $timeoutSec, string $payload): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl unavailable');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => max(1, $timeoutSec),
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $errno = curl_errno($ch);
        curl_close($ch);
        throw new RuntimeException(match ($errno) {
            CURLE_OPERATION_TIMEDOUT => 'gateway_timeout',
            CURLE_COULDNT_CONNECT => 'service_unavailable',
            default => 'proxy_error',
        });
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502;
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
    $body = substr((string)$raw, $headerSize);
    curl_close($ch);

    return ['status' => $status, 'headers' => ['Content-Type: ' . $contentType], 'body' => $body];
}

function hub_docparser_render_outputs(array $docir, array $options): array
{
    $targetLanguage = (string) ($options['target_language'] ?? $docir['target_language'] ?? 'zh-TW');
    $toc = [];
    $reader = ['<!doctype html><html><head><meta charset="utf-8"><title>DocParser Reader</title></head><body>'];
    $bilingual = ['<!doctype html><html><head><meta charset="utf-8"><title>DocParser Bilingual</title></head><body>'];
    $markdown = [];
    $chunks = [];

    foreach ($docir['blocks'] ?? [] as $block) {
        $id = (string) ($block['id'] ?? '');
        $page = (int) ($block['page'] ?? 1);
        $source = (string) ($block['source_text'] ?? '');
        $translated = hub_docparser_valid_translation_text($block);
        $readerText = $translated !== '' ? $translated : $source;
        $markdownText = $translated;
        if (($block['type'] ?? '') === 'heading') {
            $toc[] = ['title' => $source, 'level' => 1, 'page' => $page, 'block_id' => $id, 'anchor' => $id];
            $reader[] = '<h1 id="' . hub_h($id) . '">' . hub_h($readerText) . '</h1>';
            $bilingual[] = '<section id="' . hub_h($id) . '" data-page="' . $page . '"><h1>' . hub_h($source) . '</h1><p lang="' . hub_h($targetLanguage) . '">' . hub_h($translated) . '</p></section>';
            if ($markdownText !== '') {
                $markdown[] = '# ' . $markdownText;
            }
        } else {
            $reader[] = '<p id="' . hub_h($id) . '" data-page="' . $page . '">' . hub_h($readerText) . '</p>';
            $bilingual[] = '<section id="' . hub_h($id) . '" data-page="' . $page . '"><p>' . hub_h($source) . '</p><p lang="' . hub_h($targetLanguage) . '">' . hub_h($translated) . '</p></section>';
            if ($markdownText !== '') {
                $markdown[] = $markdownText;
            }
        }
        if ($translated !== '') {
            $chunks[] = [
                'block_id' => $id,
                'page' => $page,
                'section_path' => $block['section_path'] ?? [],
                'source_text' => $source,
                'text' => $translated,
                'provenance' => $block['provenance'] ?? [],
            ];
        }
    }

    $reader[] = '</body></html>';
    $bilingual[] = '</body></html>';

    return [
        'reader_html' => implode("\n", $reader),
        'bilingual_html' => implode("\n", $bilingual),
        'markdown' => trim(implode("\n\n", $markdown)) . "\n",
        'toc_json' => json_encode($toc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        'rag_chunks_json' => json_encode($chunks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
    ];
}

function hub_docparser_quality_report(array $docir, array $outputs, array $fixture): array
{
    $blocks = is_array($docir['blocks'] ?? null) ? $docir['blocks'] : [];
    $pages = is_array($docir['pages'] ?? null) ? $docir['pages'] : [];
    $translatableCount = 0;
    $translated = 0;
    $identity = 0;
    $headingCount = 0;
    $tableCount = 0;
    $figureBlockCount = 0;
    foreach ($blocks as $block) {
        $source = trim((string) ($block['source_text'] ?? ''));
        $target = hub_docparser_valid_translation_text($block);
        $type = (string) ($block['type'] ?? 'paragraph');
        if ($type === 'heading') {
            $headingCount++;
        } elseif ($type === 'table') {
            $tableCount++;
        } elseif ($type === 'figure') {
            $figureBlockCount++;
        }
        if (in_array($type, ['paragraph', 'heading', 'caption', 'list'], true)) {
            $translatableCount++;
        }
        if ($target !== '' && in_array($type, ['paragraph', 'heading', 'caption', 'list'], true)) {
            $translated++;
        }
        if ($source !== '' && $target !== '' && $source === $target && in_array($type, ['paragraph', 'heading', 'caption', 'list'], true)) {
            $identity++;
        }
    }

    $count = max(1, $translatableCount);
    $identityRatio = $identity / $count;
    $coverage = $translated / $count;
    $protected = hub_docparser_protected_token_preservation($docir, $fixture['protected_tokens'] ?? [], $outputs);
    $toc = hub_docparser_quality_json_array((string) ($outputs['toc_json'] ?? ''));
    $ragChunks = hub_docparser_quality_json_array((string) ($outputs['rag_chunks_json'] ?? ''));
    $readerHtml = (string) ($outputs['reader_html'] ?? '');
    $bilingualHtml = (string) ($outputs['bilingual_html'] ?? '');
    $markdown = (string) ($outputs['markdown'] ?? '');
    $tocTitles = [];
    $tocBrokenAnchorCount = 0;
    $readerAnchors = hub_docparser_reader_anchor_ids($readerHtml);
    foreach ($toc as $item) {
        if (!is_array($item)) {
            continue;
        }
        $title = trim((string) ($item['title'] ?? ''));
        if ($title !== '') {
            $tocTitles[] = $title;
        }
        $anchor = trim((string) ($item['anchor'] ?? ''));
        if ($anchor === '' || !in_array($anchor, $readerAnchors, true)) {
            $tocBrokenAnchorCount++;
        }
    }

    $requiredTocTitles = is_array($fixture['required_toc_titles'] ?? null) ? $fixture['required_toc_titles'] : [];
    $missingTocTitles = [];
    foreach ($requiredTocTitles as $title) {
        $title = trim((string) $title);
        if ($title !== '' && !in_array($title, $tocTitles, true)) {
            $missingTocTitles[] = $title;
        }
    }

    $requiredTranslations = is_array($fixture['required_translations'] ?? null) ? $fixture['required_translations'] : [];
    $missingTranslations = [];
    foreach ($requiredTranslations as $source => $target) {
        $target = trim((string) $target);
        if ($target !== '' && !str_contains($markdown, $target) && !str_contains($readerHtml, $target) && !str_contains($bilingualHtml, $target)) {
            $missingTranslations[(string) $source] = $target;
        }
    }

    $pageCount = count($pages);
    $expectedPageCountMin = max(1, (int) ($fixture['expected_page_count_min'] ?? 1));
    $minimumHeadingCount = max(0, (int) ($fixture['minimum_heading_count'] ?? 0));
    $minimumTableCount = max(0, (int) ($fixture['minimum_table_count'] ?? 0));
    $expectedFigureCountMin = max(0, (int) ($fixture['expected_figure_count_min'] ?? 0));
    $thresholds = is_array($fixture['quality_thresholds'] ?? null) ? $fixture['quality_thresholds'] : [];
    $figureCount = max($figureBlockCount, is_array($docir['figures'] ?? null) ? count($docir['figures']) : 0);
    $metrics = [
        'page_count' => $pageCount,
        'heading_count' => $headingCount,
        'table_count' => $tableCount,
        'figure_count' => $figureCount,
        'source_kind' => (string) ($docir['source_kind'] ?? 'structure_blocks'),
        'structure_mock' => !empty($docir['structure_mock']),
        'page_record_coverage' => hub_docparser_page_record_coverage($docir),
        'block_provenance_coverage' => hub_docparser_provenance_coverage($blocks),
        'broken_asset_links' => hub_docparser_broken_asset_links($readerHtml, $bilingualHtml),
        'orphan_figure_count' => 0,
        'toc_broken_anchor_count' => $tocBrokenAnchorCount,
        'required_artifact_integrity' => hub_docparser_required_artifact_integrity($outputs),
        'translation_block_coverage' => $coverage,
        'translation_identity_ratio' => $identityRatio,
        'protected_token_preservation' => $protected,
    ];
    $checks = [
        'source_kind' => $metrics['source_kind'] !== 'fallback_markdown',
        'structure_mock' => $metrics['structure_mock'] === false,
        'expected_page_count_min' => $pageCount >= $expectedPageCountMin,
        'minimum_heading_count' => $headingCount >= $minimumHeadingCount,
        'minimum_table_count' => $tableCount >= $minimumTableCount,
        'expected_figure_count_min' => $figureCount >= $expectedFigureCountMin,
        'required_toc_titles' => $missingTocTitles === [],
        'required_translations' => $missingTranslations === [],
        'page_record_coverage' => $metrics['page_record_coverage'] >= (float) ($thresholds['page_record_coverage'] ?? 1.0),
        'block_provenance_coverage' => $metrics['block_provenance_coverage'] >= (float) ($thresholds['block_provenance_coverage'] ?? 1.0),
        'broken_asset_links' => $metrics['broken_asset_links'] <= (int) ($thresholds['broken_asset_links'] ?? 0),
        'orphan_figure_count' => $metrics['orphan_figure_count'] <= (int) ($thresholds['orphan_figure_count'] ?? 0),
        'toc_broken_anchor_count' => $metrics['toc_broken_anchor_count'] <= (int) ($thresholds['toc_broken_anchor_count'] ?? 0),
        'required_artifact_integrity' => $metrics['required_artifact_integrity'] >= (float) ($thresholds['required_artifact_integrity'] ?? 1.0),
        'translation_block_coverage' => $metrics['translation_block_coverage'] >= (float) ($thresholds['translation_block_coverage'] ?? 0.98),
        'translation_identity_ratio' => $metrics['translation_identity_ratio'] <= (float) ($thresholds['translation_identity_ratio_max'] ?? 0.10),
        'protected_token_preservation' => $metrics['protected_token_preservation'] >= (float) ($thresholds['protected_token_preservation'] ?? 1.0),
    ];
    $failures = [];
    foreach ($checks as $name => $ok) {
        if (!$ok) {
            $failures[] = $name;
        }
    }

    return [
        'status' => $failures === [] ? 'completed' : 'needs_review',
        'metrics' => $metrics,
        'checks' => $checks,
        'failures' => $failures,
        'missing_toc_titles' => $missingTocTitles,
        'missing_translations' => $missingTranslations,
        'warnings' => $failures === [] ? [] : array_map(static fn(string $code): array => ['code' => $code], $failures),
    ];
}

function hub_docparser_valid_translation_text(array $block): string
{
    $blockId = (string) ($block['id'] ?? '');
    $translation = is_array($block['translation'] ?? null) ? $block['translation'] : [];
    $sourceBlockId = (string) ($translation['source_block_id'] ?? '');
    if ($blockId === '' || $sourceBlockId !== $blockId) {
        return '';
    }

    return trim((string) ($translation['text'] ?? ''));
}

function hub_docparser_provenance_coverage(array $blocks): float
{
    if ($blocks === []) {
        return 0.0;
    }

    $ok = 0;
    foreach ($blocks as $block) {
        if (($block['provenance']['source_block_id'] ?? '') !== '') {
            $ok++;
        }
    }

    return $ok / count($blocks);
}

function hub_docparser_page_record_coverage(array $docir): float
{
    $pages = is_array($docir['pages'] ?? null) ? $docir['pages'] : [];
    $blocks = is_array($docir['blocks'] ?? null) ? $docir['blocks'] : [];
    if ($blocks === []) {
        return $pages === [] ? 0.0 : 1.0;
    }

    $pageRecords = [];
    foreach ($pages as $page) {
        if (is_array($page)) {
            $pageRecords[(int) ($page['page'] ?? 0)] = true;
        }
    }
    $blockPages = [];
    foreach ($blocks as $block) {
        $pageNo = (int) ($block['page'] ?? 0);
        if ($pageNo > 0) {
            $blockPages[$pageNo] = true;
        }
    }
    if ($blockPages === []) {
        return $pageRecords === [] ? 0.0 : 1.0;
    }

    $covered = 0;
    foreach (array_keys($blockPages) as $pageNo) {
        if (isset($pageRecords[$pageNo])) {
            $covered++;
        }
    }

    return $covered / count($blockPages);
}

function hub_docparser_protected_token_preservation(array $docir, array $tokens, array $outputs = []): float
{
    if ($tokens === []) {
        return 1.0;
    }

    $sourceText = '';
    foreach ($docir['blocks'] ?? [] as $block) {
        $sourceText .= "\n" . (string) ($block['source_text'] ?? '');
    }

    $requiredTokens = [];
    foreach ($tokens as $token) {
        $token = (string) $token;
        if ($token !== '' && str_contains($sourceText, $token)) {
            $requiredTokens[] = $token;
        }
    }
    if ($requiredTokens === []) {
        return 1.0;
    }

    $translatedText = '';
    foreach ($docir['blocks'] ?? [] as $block) {
        if (!is_array($block)) {
            continue;
        }
        $translatedText .= "\n" . hub_docparser_valid_translation_text($block);
    }
    foreach (['reader_html', 'bilingual_html', 'markdown'] as $key) {
        $translatedText .= "\n" . (string) ($outputs[$key] ?? '');
    }

    $kept = 0;
    foreach ($requiredTokens as $token) {
        if (str_contains($translatedText, $token)) {
            $kept++;
        }
    }

    return $kept / count($requiredTokens);
}

function hub_docparser_required_artifact_integrity(array $outputs): float
{
    $readerHtml = trim((string) ($outputs['reader_html'] ?? ''));
    $bilingualHtml = trim((string) ($outputs['bilingual_html'] ?? ''));
    $markdown = trim((string) ($outputs['markdown'] ?? ''));
    if ($readerHtml === '' || $bilingualHtml === '' || $markdown === '') {
        return 0.0;
    }
    if (!str_contains($readerHtml, '<html') || !str_contains($bilingualHtml, '<html')) {
        return 0.0;
    }
    if (hub_docparser_quality_json_array((string) ($outputs['toc_json'] ?? '')) === [] && trim((string) ($outputs['toc_json'] ?? '')) !== '[]') {
        return 0.0;
    }
    if (hub_docparser_quality_json_array((string) ($outputs['rag_chunks_json'] ?? '')) === [] && trim((string) ($outputs['rag_chunks_json'] ?? '')) !== '[]') {
        return 0.0;
    }

    return 1.0;
}

function hub_docparser_quality_json_array(string $json): array
{
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function hub_docparser_reader_anchor_ids(string $html): array
{
    preg_match_all('/id="([^"]+)"/', $html, $matches);
    return is_array($matches[1] ?? null) ? $matches[1] : [];
}

function hub_docparser_broken_asset_links(string $readerHtml, string $bilingualHtml): int
{
    $count = 0;
    preg_match_all('/(?:src|href)="([^"]+)"/', $readerHtml . "\n" . $bilingualHtml, $matches);
    foreach ($matches[1] ?? [] as $link) {
        $link = trim((string) $link);
        if ($link === '' || str_starts_with($link, '#') || preg_match('/^(https?:|data:|mailto:)/i', $link)) {
            continue;
        }
        $count++;
    }

    return $count;
}
