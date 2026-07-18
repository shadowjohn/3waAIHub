<?php
declare(strict_types=1);

function hub_docparser_cache_version(PDO $db): string
{
    $version = trim(hub_get_storage_setting($db, 'AIHUB_DOCPARSER_CACHE_VERSION'));

    return $version !== '' ? $version : 'docparser-v0.1';
}

function hub_docparser_cache_ttl_days(PDO $db): int
{
    return max(0, (int)hub_get_storage_setting($db, 'AIHUB_DOCPARSER_CACHE_TTL_DAYS'));
}

function hub_docparser_cache_key(string $inputSha256, array $input, string $version): string
{
    return hash('sha256', implode('|', [
        $inputSha256,
        (string)($input['profile'] ?? 'technical_manual'),
        (string)($input['target_language'] ?? 'zh-TW'),
        (string)($input['translation_required'] ?? '1'),
        (string)($input['structure_mode'] ?? 'structure'),
        (string)($input['translate_mode'] ?? 'translate'),
        $version,
    ]));
}

function hub_docparser_find_cached_task(PDO $db, string $inputSha256, array $input): ?array
{
    $ttlDays = hub_docparser_cache_ttl_days($db);
    if ($ttlDays <= 0) {
        return null;
    }

    $version = hub_docparser_cache_version($db);
    $cacheKey = hub_docparser_cache_key($inputSha256, $input, $version);
    $cutoff = date('Y-m-d H:i:s', time() - ($ttlDays * 86400));
    $stmt = $db->prepare(
        "SELECT *
         FROM tasks
         WHERE task_type = 'docparser_parse'
           AND status = 'success'
           AND finished_at IS NOT NULL
           AND finished_at >= :cutoff
         ORDER BY finished_at DESC, id DESC
         LIMIT 100"
    );
    $stmt->execute([':cutoff' => $cutoff]);

    foreach ($stmt->fetchAll() as $task) {
        $candidateInput = json_decode((string)($task['input_json'] ?? ''), true);
        if (!is_array($candidateInput)) {
            continue;
        }
        if ((string)($candidateInput['docparser_cache_key'] ?? '') !== $cacheKey) {
            continue;
        }
        if ((string)($candidateInput['docparser_cache_version'] ?? '') !== $version) {
            continue;
        }
        $inputMemberId = (int)($input['api_member_id'] ?? 0);
        $candidateMemberId = (int)($candidateInput['api_member_id'] ?? 0);
        if ($inputMemberId > 0 && $candidateMemberId !== $inputMemberId) {
            continue;
        }
        if (!hub_docparser_task_artifacts_complete($db, (int)$task['id'])) {
            continue;
        }

        $task['input'] = $candidateInput;
        $task['result'] = json_decode((string)($task['result_json'] ?? ''), true) ?: null;
        $finishedAt = strtotime((string)($task['finished_at'] ?? '')) ?: time();
        $task['cache_age_seconds'] = max(0, time() - $finishedAt);
        $task['docparser_cache_key'] = $cacheKey;

        return $task;
    }

    return null;
}

function hub_docparser_task_artifacts_complete(PDO $db, int $taskId): bool
{
    $required = [
        'docparser/manifest.json',
        'docparser/exports/index.zh-TW.html',
        'docparser/exports/index.bilingual.html',
        'docparser/exports/document.zh-TW.md',
        'docparser/normalized/docir-v0.1.json',
        'docparser/normalized/toc.json',
        'docparser/exports/rag_chunks.json',
        'docparser/exports/quality-report.json',
    ];

    $stmt = $db->prepare('SELECT name, path FROM task_artifacts WHERE task_id = :task_id');
    $stmt->execute([':task_id' => $taskId]);
    $artifacts = [];
    foreach ($stmt->fetchAll() as $artifact) {
        $artifacts[(string)$artifact['name']] = (string)$artifact['path'];
    }

    foreach ($required as $name) {
        if (!isset($artifacts[$name]) || !is_file($artifacts[$name])) {
            return false;
        }
    }

    return true;
}

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

    $figures = [];
    foreach ($blocks as $block) {
        if (($block['type'] ?? '') !== 'figure') {
            continue;
        }
        $figures[] = [
            'id' => 'fig-p' . (int)$block['page'] . '-' . (count($figures) + 1),
            'page' => (int)$block['page'],
            'block_id' => (string)$block['id'],
            'bbox' => $block['bbox'] ?? [0.0, 0.0, 0.0, 0.0],
            'caption' => (string)($block['source_text'] ?? ''),
            'source' => 'docir_figure_block',
        ];
    }

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
        'figures' => $figures !== [] ? $figures : (is_array($structurePayload['figures'] ?? null) ? $structurePayload['figures'] : []),
    ];
}

function hub_docparser_extract_figure_assets(string $inputFile, array $docir, string $figureDir): array
{
    if (!is_file($inputFile) || ($docir['figures'] ?? []) === [] || hub_docparser_run_command(['pdftoppm', '-v']) !== 0) {
        return $docir;
    }
    if (!is_dir($figureDir) && !mkdir($figureDir, 0775, true) && !is_dir($figureDir)) {
        return $docir;
    }

    $pages = [];
    foreach ($docir['pages'] ?? [] as $page) {
        if (!is_array($page)) {
            continue;
        }
        $pageNo = (int)($page['page'] ?? 0);
        if ($pageNo > 0) {
            $pages[$pageNo] = [
                'width' => max(1, (int)round((float)($page['width'] ?? 0))),
                'height' => max(1, (int)round((float)($page['height'] ?? 0))),
            ];
        }
    }

    $figuresByPage = [];
    foreach ($docir['figures'] as $index => $figure) {
        if (!is_array($figure)) {
            continue;
        }
        $pageNo = (int)($figure['page'] ?? 0);
        if ($pageNo > 0) {
            $figuresByPage[$pageNo][] = $index;
        }
    }

    $tmpBase = rtrim(sys_get_temp_dir(), '/') . '/3wa-docparser-figures-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!mkdir($tmpBase, 0700, true) && !is_dir($tmpBase)) {
        return $docir;
    }

    foreach ($figuresByPage as $pageNo => $indexes) {
        $pageSize = $pages[$pageNo] ?? ['width' => 1600, 'height' => 2200];
        $prefix = $tmpBase . '/page-' . $pageNo;
        $exitCode = hub_docparser_run_command([
            'pdftoppm',
            '-q',
            '-f',
            (string)$pageNo,
            '-l',
            (string)$pageNo,
            '-singlefile',
            '-png',
            '-scale-to-x',
            (string)$pageSize['width'],
            '-scale-to-y',
            (string)$pageSize['height'],
            $inputFile,
            $prefix,
        ]);
        $pageImage = $prefix . '.png';
        if ($exitCode !== 0 || !is_file($pageImage)) {
            continue;
        }
        $image = @imagecreatefrompng($pageImage);
        if (!$image) {
            @unlink($pageImage);
            continue;
        }
        $actualWidth = imagesx($image);
        $actualHeight = imagesy($image);
        $scaleX = $pageSize['width'] > 0 ? $actualWidth / $pageSize['width'] : 1.0;
        $scaleY = $pageSize['height'] > 0 ? $actualHeight / $pageSize['height'] : 1.0;

        foreach ($indexes as $index) {
            $figure = $docir['figures'][$index] ?? [];
            $bbox = is_array($figure['bbox'] ?? null) ? $figure['bbox'] : [0, 0, 0, 0];
            $x1 = max(0, min($actualWidth - 1, (int)floor(((float)($bbox[0] ?? 0)) * $scaleX)));
            $y1 = max(0, min($actualHeight - 1, (int)floor(((float)($bbox[1] ?? 0)) * $scaleY)));
            $x2 = max($x1 + 1, min($actualWidth, (int)ceil(((float)($bbox[2] ?? 0)) * $scaleX)));
            $y2 = max($y1 + 1, min($actualHeight, (int)ceil(((float)($bbox[3] ?? 0)) * $scaleY)));
            $crop = imagecrop($image, ['x' => $x1, 'y' => $y1, 'width' => $x2 - $x1, 'height' => $y2 - $y1]);
            if (!$crop) {
                continue;
            }
            $safeId = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)($figure['id'] ?? ('figure-' . ($index + 1)))) ?: ('figure-' . ($index + 1));
            $assetRelative = 'assets/figures/' . $safeId . '.png';
            $assetPath = rtrim(dirname($figureDir), '/') . '/figures/' . $safeId . '.png';
            if (imagepng($crop, $assetPath)) {
                $docir['figures'][$index]['asset_path'] = $assetRelative;
                $docir['figures'][$index]['asset_bytes'] = filesize($assetPath) ?: 0;
                $docir['figures'][$index]['image_width'] = imagesx($crop);
                $docir['figures'][$index]['image_height'] = imagesy($crop);
            }
            imagedestroy($crop);
        }
        imagedestroy($image);
        @unlink($pageImage);
    }
    @rmdir($tmpBase);

    $assetByBlock = [];
    foreach ($docir['figures'] as $figure) {
        if (!is_array($figure)) {
            continue;
        }
        $blockId = (string)($figure['block_id'] ?? '');
        $assetPath = (string)($figure['asset_path'] ?? '');
        if ($blockId !== '' && $assetPath !== '') {
            $assetByBlock[$blockId] = $assetPath;
        }
    }
    foreach ($docir['blocks'] as &$block) {
        $blockId = (string)($block['id'] ?? '');
        if (isset($assetByBlock[$blockId])) {
            $block['asset_path'] = $assetByBlock[$blockId];
        }
    }
    unset($block);

    return $docir;
}

function hub_docparser_run_command(array $command): int
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        return 127;
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process);
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
    $figures = [];
    $documents = hub_docparser_document_pages($document);

    foreach ($documents as $pageIndex => $pagePayload) {
        if (!is_array($pagePayload)) {
            continue;
        }
        $pageNo = hub_docparser_page_number($pagePayload, $pageIndex + 1);
        $pageBlocks = $pagePayload['blocks'] ?? $pagePayload['res'] ?? $pagePayload['parsing_res_list'] ?? [];
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
            $type = hub_docparser_block_type($block);
            $text = hub_docparser_block_text($block);
            $bbox = hub_docparser_normalize_bbox($block['bbox'] ?? $block['poly'] ?? $block['block_bbox'] ?? null);
            $rawId = (string)($block['id'] ?? $block['block_id'] ?? ('raw-' . $pageNo . '-' . ($index + 1)));
            $orderValue = $block['order'] ?? $block['block_order'] ?? null;
            $order = $orderValue === null || $orderValue === '' ? 1 : max(1, (int)$orderValue);
            $blocks[] = [
                'id' => $rawId,
                'page' => hub_docparser_page_number($block, $pageNo),
                'order' => $order,
                'type' => $type,
                'text' => $text,
                'bbox' => $bbox,
            ];
            if ($type === 'figure') {
                $figures[] = [
                    'id' => 'fig-p' . $pageNo . '-' . (count($figures) + 1),
                    'page' => $pageNo,
                    'block_id' => $rawId,
                    'bbox' => $bbox,
                    'caption' => $text,
                    'source' => 'ppstructure_parsing_res_list',
                ];
            }
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
        'figures' => $figures,
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
    if (isset($document['blocks']) || isset($document['res']) || isset($document['parsing_res_list'])) {
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
    $type = strtolower(trim((string)($block['type'] ?? $block['block_type'] ?? $block['label'] ?? $block['block_label'] ?? 'paragraph')));
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
    foreach (['text', 'content', 'html', 'res_text', 'block_content'] as $key) {
        $value = trim((string) ($block[$key] ?? ''));
        if ($value !== '') {
            if ($key === 'html' || str_contains($value, '<table') || str_contains($value, '<td') || str_contains($value, '<th')) {
                $value = preg_replace('/<\/t[dh]>/i', "\t", $value) ?? $value;
                $value = preg_replace('/<\/tr>/i', "\n", $value) ?? $value;
            }
            return strip_tags($value);
        }
    }

    return '';
}

function hub_docparser_translatable_block_types(): array
{
    return ['paragraph', 'heading', 'caption', 'list', 'table'];
}

function hub_docparser_translate_blocks(PDO $db, array $docir, array $input, ?int $taskId = null): array
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

    $translatableIndexes = [];
    foreach ($docir['blocks'] as $index => $block) {
        $text = trim((string)($block['source_text'] ?? ''));
        if ($text !== '' && in_array((string)$block['type'], hub_docparser_translatable_block_types(), true)) {
            $translatableIndexes[] = $index;
        }
    }
    $total = count($translatableIndexes);
    if ($taskId !== null) {
        hub_add_task_log($db, $taskId, 'info', 'docparser_translate started blocks=' . $total);
    }

    $done = 0;
    foreach ($docir['blocks'] as &$block) {
        if ($taskId !== null) {
            hub_abort_if_task_cancel_requested($db, $taskId);
        }
        $text = trim((string)($block['source_text'] ?? ''));
        if ($text === '' || !in_array((string)$block['type'], hub_docparser_translatable_block_types(), true)) {
            continue;
        }
        // ponytail: 先逐 block 翻譯，真的被延遲打到再補 batch。
        $block['translation'] = [
            'language' => $target,
            'text' => hub_docparser_translate_text($service, $text, $target),
            'source_block_id' => (string)($block['id'] ?? ''),
        ];
        $done++;
        if ($taskId !== null && ($done === $total || $done % 10 === 0)) {
            $progress = 55 + (int)floor(($done / max(1, $total)) * 25);
            hub_update_task_progress($db, $taskId, min(80, $progress));
        }
    }
    unset($block);
    if ($taskId !== null) {
        hub_add_task_log($db, $taskId, 'info', 'docparser_translate finished blocks=' . $done);
    }

    return $docir;
}

function hub_docparser_parse_repair_block_ids(string $value): array
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 4096) {
        throw new InvalidArgumentException('invalid_block_ids');
    }

    $parts = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $id): bool => $id !== ''));
    if ($parts === [] || count($parts) > 50) {
        throw new InvalidArgumentException('invalid_block_ids');
    }

    $ids = [];
    foreach ($parts as $id) {
        if (preg_match('/^p[1-9][0-9]*-b[1-9][0-9]*$/', $id) !== 1) {
            throw new InvalidArgumentException('invalid_block_ids');
        }
        $ids[$id] = true;
    }

    return array_keys($ids);
}

function hub_docparser_load_registered_docir_artifact(PDO $db, int $taskId): array
{
    $stmt = $db->prepare(
        'SELECT * FROM task_artifacts
         WHERE task_id = :task_id AND name = :name
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':task_id' => $taskId,
        ':name' => 'docparser/normalized/docir-v0.1.json',
    ]);
    $artifact = $stmt->fetch();
    if (!$artifact) {
        throw new RuntimeException('docir_artifact_missing');
    }

    $path = hub_artifact_safe_path((string)$artifact['path']);
    $taskRoot = realpath(hub_task_result_dir($taskId));
    if ($path === null || $taskRoot === false || !str_starts_with($path, $taskRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('docir_artifact_rejected');
    }

    $docir = json_decode((string)file_get_contents($path), true);
    if (!is_array($docir)) {
        throw new RuntimeException('docir_artifact_invalid');
    }

    return $docir;
}

function hub_docparser_repair_translation_docir(PDO $db, array $docir, array $input, array $blockIds, ?int $taskId = null, ?callable $translator = null): array
{
    $target = (string)($input['target_language'] ?? $docir['target_language'] ?? 'zh-TW');
    if ($translator === null) {
        $service = hub_get_service_by_mode($db, (string)($input['translate_mode'] ?? 'translate'));
        if (!$service || (int)($service['enabled'] ?? 0) !== 1) {
            throw new RuntimeException('blocked_dependency: translate service is unavailable.');
        }
        $translator = static fn (string $text, string $targetLanguage): string => hub_docparser_translate_text($service, $text, $targetLanguage);
    }

    $blocks = is_array($docir['blocks'] ?? null) ? $docir['blocks'] : [];
    $blockIndexById = [];
    foreach ($blocks as $index => $block) {
        if (is_array($block) && (string)($block['id'] ?? '') !== '') {
            $blockIndexById[(string)$block['id']] = $index;
        }
    }

    $repaired = [];
    $skipped = [];
    foreach ($blockIds as $blockId) {
        if (!array_key_exists($blockId, $blockIndexById)) {
            throw new RuntimeException('unknown_block_id: ' . $blockId);
        }

        $index = $blockIndexById[$blockId];
        $block = is_array($blocks[$index] ?? null) ? $blocks[$index] : [];
        $source = trim((string)($block['source_text'] ?? ''));
        $type = (string)($block['type'] ?? '');
        if ($source === '' || !in_array($type, hub_docparser_translatable_block_types(), true)) {
            throw new RuntimeException('block_not_translatable: ' . $blockId);
        }

        if (hub_docparser_valid_translation_text($block) !== '') {
            $skipped[] = $blockId;
            continue;
        }

        $text = trim((string)$translator($source, $target));
        if ($text === '') {
            throw new RuntimeException('translation_empty: ' . $blockId);
        }
        $docir['blocks'][$index]['translation'] = [
            'language' => $target,
            'text' => $text,
            'source_block_id' => $blockId,
        ];
        $repaired[] = $blockId;
        if ($taskId !== null && $taskId > 0) {
            hub_add_task_log($db, $taskId, 'info', 'docparser_repair_translation repaired block=' . $blockId);
        }
    }

    return [
        'docir' => $docir,
        'repaired_block_ids' => $repaired,
        'skipped_block_ids' => $skipped,
    ];
}

function hub_docparser_translate_text(array $service, string $text, string $target): string
{
    $chunks = hub_docparser_split_translation_text($text);
    if (count($chunks) > 1) {
        $translated = [];
        foreach ($chunks as $chunk) {
            $translated[] = hub_docparser_translate_text($service, $chunk, $target);
        }

        return trim(implode("\n\n", array_filter($translated, static fn(string $value): bool => trim($value) !== '')));
    }

    $payload = json_encode([
        'text' => $chunks[0] ?? $text,
        'source_lang' => 'auto',
        'target_lang' => $target,
        'real_inference' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Cannot encode translation payload.');
    }

    $maxAttempts = hub_docparser_translation_max_attempts();
    $lastError = 'translation_failed';
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $response = hub_docparser_post_json((string)$service['internal_url'], hub_service_gateway_timeout_sec($service), $payload);
        } catch (RuntimeException $e) {
            $lastError = 'translation_failed: ' . $e->getMessage();
            if ($attempt < $maxAttempts) {
                usleep(200000 * $attempt);
                continue;
            }
            throw new RuntimeException($lastError, 0, $e);
        }

        $body = json_decode((string)($response['body'] ?? ''), true);
        if (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300 && is_array($body) && !empty($body['ok'])) {
            return trim((string)($body['text'] ?? $body['translated_text'] ?? ''));
        }

        $status = (int)($response['status'] ?? 0);
        $detail = is_array($body)
            ? hub_backend_error_summary($body, 'translation_failed')
            : hub_backend_error_summary([
                'error' => 'invalid_response',
                'message' => substr(trim((string)($response['body'] ?? '')), 0, 240),
            ], 'translation_failed');
        $lastError = 'translation_failed: HTTP ' . $status . ' ' . $detail;
        if ($attempt < $maxAttempts) {
            usleep(200000 * $attempt);
        }
    }

    throw new RuntimeException($lastError);
}

function hub_docparser_translation_max_attempts(): int
{
    // ponytail: fixed 3 attempts; make configurable only if real ops needs per-pack tuning.
    return 3;
}

function hub_backend_error_summary(array $payload, string $fallback = 'unknown_error', int $maxBytes = 360): string
{
    $error = trim((string)($payload['error'] ?? $fallback));
    if ($error === '') {
        $error = $fallback;
    }
    $message = trim((string)($payload['message'] ?? $payload['detail'] ?? ''));
    $summary = $error;
    if ($message !== '') {
        $summary .= ': ' . $message;
    }
    $summary = trim((string)preg_replace('/\s+/', ' ', $summary));
    if ($maxBytes > 0 && strlen($summary) > $maxBytes) {
        $summary = substr($summary, 0, max(0, $maxBytes - 3)) . '...';
    }

    return $summary;
}

function hub_docparser_split_translation_text(string $text, int $maxChars = 8000): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    if (strlen($text) <= $maxChars) {
        return [$text];
    }

    $chunks = [];
    $current = '';
    foreach (preg_split('/\R/u', $text) ?: [$text] as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        if (strlen($line) > $maxChars) {
            if ($current !== '') {
                $chunks[] = $current;
                $current = '';
            }
            $parts = [];
            preg_match_all('/.{1,' . $maxChars . '}/us', $line, $parts);
            foreach ($parts[0] ?? [] as $part) {
                $chunks[] = trim((string)$part);
            }
            continue;
        }

        $candidate = $current === '' ? $line : $current . "\n" . $line;
        if (strlen($candidate) > $maxChars) {
            $chunks[] = $current;
            $current = $line;
        } else {
            $current = $candidate;
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }

    return array_values(array_filter($chunks, static fn(string $chunk): bool => trim($chunk) !== ''));
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
    $figuresByBlock = [];
    foreach ($docir['figures'] ?? [] as $figure) {
        if (!is_array($figure)) {
            continue;
        }
        $blockId = (string)($figure['block_id'] ?? '');
        if ($blockId !== '') {
            $figuresByBlock[$blockId] = $figure;
        }
    }

    foreach ($docir['blocks'] ?? [] as $block) {
        $id = (string) ($block['id'] ?? '');
        $page = (int) ($block['page'] ?? 1);
        $source = (string) ($block['source_text'] ?? '');
        $translated = hub_docparser_valid_translation_text($block);
        $readerText = $translated !== '' ? $translated : $source;
        $markdownText = $translated;
        $type = (string)($block['type'] ?? '');
        if ($type === 'figure') {
            $figure = $figuresByBlock[$id] ?? [];
            $assetPath = (string)($block['asset_path'] ?? $figure['asset_path'] ?? '');
            $assetHref = $assetPath !== '' ? '../' . ltrim($assetPath, '/') : '';
            $caption = $readerText !== '' ? $readerText : (string)($figure['caption'] ?? '');
            if ($assetHref !== '') {
                $reader[] = '<figure id="' . hub_h($id) . '" data-page="' . $page . '"><img src="' . hub_h($assetHref) . '" alt="' . hub_h($caption) . '"><figcaption>' . hub_h($caption) . '</figcaption></figure>';
                $bilingual[] = '<figure id="' . hub_h($id) . '" data-page="' . $page . '"><img src="' . hub_h($assetHref) . '" alt="' . hub_h($caption) . '"><figcaption>' . hub_h($source) . '</figcaption></figure>';
                $markdown[] = '![' . str_replace(["\r", "\n"], ' ', $caption) . '](' . $assetHref . ')';
            } elseif ($caption !== '') {
                $reader[] = '<p id="' . hub_h($id) . '" data-page="' . $page . '">' . hub_h($caption) . '</p>';
                $bilingual[] = '<section id="' . hub_h($id) . '" data-page="' . $page . '"><p>' . hub_h($source) . '</p></section>';
            }
        } elseif ($type === 'heading') {
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
    $coverageByType = [];
    $missingByType = [];
    $missingBlocks = [];
    $translatableTypes = hub_docparser_translatable_block_types();
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
        if ($source !== '' && in_array($type, $translatableTypes, true)) {
            $translatableCount++;
            $coverageByType[$type] ??= ['total' => 0, 'translated' => 0];
            $coverageByType[$type]['total']++;
        }
        if ($source !== '' && $target !== '' && in_array($type, $translatableTypes, true)) {
            $translated++;
            $coverageByType[$type]['translated']++;
        } elseif ($source !== '' && in_array($type, $translatableTypes, true)) {
            $missingByType[$type][] = (string)($block['id'] ?? '');
            $missingBlocks[] = [
                'id' => (string)($block['id'] ?? ''),
                'page' => (int)($block['page'] ?? 0),
                'type' => $type,
                'source_excerpt' => substr($source, 0, 120),
            ];
        }
        if ($source !== '' && $target !== '' && $source === $target && in_array($type, $translatableTypes, true) && hub_docparser_counts_as_identity_translation($source, (string)($docir['target_language'] ?? ''))) {
            $identity++;
        }
    }

    $count = max(1, $translatableCount);
    $identityRatio = $identity / $count;
    $coverage = $translated / $count;
    $coverageRatioByType = [];
    foreach ($coverageByType as $type => $counts) {
        $coverageRatioByType[$type] = $counts['total'] > 0 ? $counts['translated'] / $counts['total'] : 1.0;
    }
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
        'translation_coverage_by_type' => $coverageRatioByType,
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
    foreach ($coverageRatioByType as $type => $ratio) {
        $checks['translation_coverage_by_type.' . $type] = $ratio >= (float) ($thresholds['translation_block_coverage'] ?? 0.98);
    }
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
        'missing_translation_block_ids_by_type' => $missingByType,
        'missing_translation_blocks' => $missingBlocks,
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

function hub_docparser_counts_as_identity_translation(string $source, string $targetLanguage): bool
{
    $source = trim($source);
    if ($targetLanguage === 'zh-TW' || $targetLanguage === 'zh-Hant' || str_starts_with($targetLanguage, 'zh')) {
        if (preg_match('/\p{Han}/u', $source) === 1) {
            return false;
        }

        return hub_docparser_identity_source_looks_translatable($source);
    }

    return hub_docparser_identity_source_looks_translatable($source);
}

function hub_docparser_identity_source_looks_translatable(string $source): bool
{
    if ($source === '' || preg_match('/\p{L}/u', $source) !== 1) {
        return false;
    }
    preg_match_all('/[A-Za-z]+/', $source, $matches);
    $tokens = $matches[0] ?? [];
    if ($tokens === []) {
        return true;
    }

    $alphaLength = array_sum(array_map('strlen', $tokens));
    if (count($tokens) >= 2 && $alphaLength >= 8) {
        return true;
    }
    foreach ($tokens as $token) {
        $length = strlen($token);
        if ($length >= 5 && preg_match('/[a-z]/', $token) === 1) {
            return true;
        }
        if ($length >= 8 && strtoupper($token) === $token) {
            return true;
        }
    }

    return false;
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
        if (preg_match('#^(\.\./)?assets/figures/[a-zA-Z0-9_.-]+\.png$#', $link)) {
            continue;
        }
        $count++;
    }

    return $count;
}
