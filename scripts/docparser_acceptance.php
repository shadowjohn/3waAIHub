<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function hub_docparser_acceptance_result(PDO $db, int $taskId, array $fixture): array
{
    $stmt = $db->prepare('SELECT name, path FROM task_artifacts WHERE task_id = :task_id AND name LIKE :prefix');
    $stmt->execute([
        ':task_id' => $taskId,
        ':prefix' => 'docparser/%',
    ]);

    $paths = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $paths[(string)$row['name']] = (string)$row['path'];
    }

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

    $missing = [];
    foreach ($required as $name) {
        if (($paths[$name] ?? '') === '' || !is_file($paths[$name])) {
            $missing[] = $name;
        }
    }
    if ($missing !== []) {
        return [
            'ok' => false,
            'task_id' => $taskId,
            'error' => 'missing_artifacts',
            'missing' => $missing,
        ];
    }

    $manifest = hub_docparser_acceptance_json_file($paths['docparser/manifest.json']);
    $docir = hub_docparser_acceptance_json_file($paths['docparser/normalized/docir-v0.1.json']);
    $toc = hub_docparser_acceptance_json_file($paths['docparser/normalized/toc.json']);
    $chunks = hub_docparser_acceptance_json_file($paths['docparser/exports/rag_chunks.json']);
    $reportedQuality = hub_docparser_acceptance_json_file($paths['docparser/exports/quality-report.json']);
    $readerHtml = (string)file_get_contents($paths['docparser/exports/index.zh-TW.html']);
    $bilingualHtml = (string)file_get_contents($paths['docparser/exports/index.bilingual.html']);
    $markdown = (string)file_get_contents($paths['docparser/exports/document.zh-TW.md']);
    $recomputedQuality = hub_docparser_quality_report($docir, [
        'reader_html' => $readerHtml,
        'bilingual_html' => $bilingualHtml,
        'markdown' => $markdown,
        'toc_json' => (string)file_get_contents($paths['docparser/normalized/toc.json']),
        'rag_chunks_json' => (string)file_get_contents($paths['docparser/exports/rag_chunks.json']),
    ], $fixture);
    $metrics = is_array($recomputedQuality['metrics'] ?? null) ? $recomputedQuality['metrics'] : [];
    $thresholds = is_array($fixture['quality_thresholds'] ?? null) ? $fixture['quality_thresholds'] : [];
    $checks = [
        'manifest_completed' => ($manifest['status'] ?? '') === 'completed',
        'docir_schema_ok' => ($docir['schema'] ?? '') === '3wa-docir-v0.1',
        'reader_has_markup' => str_contains($readerHtml, '<html') && str_contains($readerHtml, 'id="'),
        'bilingual_has_markup' => str_contains($bilingualHtml, '<html') && str_contains($bilingualHtml, 'lang='),
        'markdown_has_content' => trim($markdown) !== '',
        'rag_chunks_present' => is_array($chunks) && $chunks !== [],
        'source_not_fallback' => (string)($metrics['source_kind'] ?? '') !== 'fallback_markdown',
        'structure_not_mock' => empty($metrics['structure_mock']),
        'expected_page_count_min' => (int)($metrics['page_count'] ?? 0) >= max(1, (int)($fixture['expected_page_count_min'] ?? 1)),
        'minimum_heading_count' => (int)($metrics['heading_count'] ?? 0) >= max(0, (int)($fixture['minimum_heading_count'] ?? 0)),
        'minimum_table_count' => (int)($metrics['table_count'] ?? 0) >= max(0, (int)($fixture['minimum_table_count'] ?? 0)),
        'expected_figure_count_min' => (int)($metrics['figure_count'] ?? 0) >= max(0, (int)($fixture['expected_figure_count_min'] ?? 0)),
        'required_toc_titles_present' => ($recomputedQuality['checks']['required_toc_titles'] ?? false) === true,
        'required_translations_present' => ($recomputedQuality['checks']['required_translations'] ?? false) === true,
        'page_record_coverage' => (float)($metrics['page_record_coverage'] ?? 0.0) >= (float)($thresholds['page_record_coverage'] ?? 1.0),
        'block_provenance_coverage' => (float)($metrics['block_provenance_coverage'] ?? 0.0) >= (float)($thresholds['block_provenance_coverage'] ?? 1.0),
        'broken_asset_links' => (int)($metrics['broken_asset_links'] ?? 0) <= (int)($thresholds['broken_asset_links'] ?? 0),
        'toc_broken_anchor_count' => (int)($metrics['toc_broken_anchor_count'] ?? 0) <= (int)($thresholds['toc_broken_anchor_count'] ?? 0),
        'required_artifact_integrity' => (float)($metrics['required_artifact_integrity'] ?? 0.0) >= (float)($thresholds['required_artifact_integrity'] ?? 1.0),
        'translation_block_coverage' => (float)($metrics['translation_block_coverage'] ?? 0.0) >= (float)($thresholds['translation_block_coverage'] ?? 0.98),
        'translation_identity_ratio' => (float)($metrics['translation_identity_ratio'] ?? 1.0) <= (float)($thresholds['translation_identity_ratio_max'] ?? 0.10),
        'protected_token_preservation' => (float)($metrics['protected_token_preservation'] ?? 0.0) >= (float)($thresholds['protected_token_preservation'] ?? 1.0),
    ];

    $failures = [];
    foreach ($checks as $name => $ok) {
        if (!$ok) {
            $failures[] = $name;
        }
    }

    return [
        'ok' => $failures === [],
        'task_id' => $taskId,
        'status' => $failures === [] ? 'completed' : 'needs_review',
        'failures' => $failures,
        'missing_toc_titles' => $recomputedQuality['missing_toc_titles'] ?? [],
        'missing_translations' => $recomputedQuality['missing_translations'] ?? [],
        'metrics' => $metrics,
        'checks' => $checks,
        'reported_quality' => [
            'status' => $reportedQuality['status'] ?? null,
            'metrics' => $reportedQuality['metrics'] ?? [],
        ],
        'recomputed_quality_status' => $recomputedQuality['status'] ?? 'needs_review',
    ];
}

function hub_docparser_acceptance_json_file(string $path): array
{
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function hub_docparser_acceptance_main(array $argv): int
{
    hub_cli_only();

    $taskId = 0;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--task-id=')) {
            $taskId = (int)substr($arg, 10);
        }
    }

    if ($taskId <= 0) {
        fwrite(STDERR, "usage: php scripts/docparser_acceptance.php --task-id=123\n");
        return 2;
    }

    $db = hub_db();
    hub_migrate($db);
    $fixture = json_decode((string)file_get_contents(HUB_ROOT . '/packs/docparser/acceptance/technical_manual_v0.1.json'), true) ?: [];
    $result = hub_docparser_acceptance_result($db, $taskId, $fixture);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    return ($result['ok'] ?? false) ? 0 : 1;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(hub_docparser_acceptance_main($argv));
}
