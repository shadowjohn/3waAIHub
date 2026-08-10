<?php
declare(strict_types=1);

function hub_admin_market_categories(): array
{
    return [
        'all' => '全部',
        'reference' => '參考樣板',
        'vision' => '視覺影像',
        'language' => '語言文字',
        'audio' => '音訊語音',
        'tools' => '工具',
        'experimental' => '實驗中',
    ];
}

function hub_admin_market_category(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === 'utility') {
        $value = 'tools';
    }
    return array_key_exists($value, hub_admin_market_categories()) ? $value : 'all';
}

function hub_admin_market_category_for_manifest(array $manifest): string
{
    if (strtolower((string)($manifest['role'] ?? '')) === 'reference') {
        return 'reference';
    }
    if (!empty($manifest['experimental'])) {
        return 'experimental';
    }
    $category = strtolower((string)($manifest['category'] ?? ''));
    if (in_array($category, ['vision', 'ocr', 'segmentation', 'detection', 'object-detection'], true)) {
        return 'vision';
    }
    if (in_array($category, ['language', 'translation', 'translate', 'llm'], true)) {
        return 'language';
    }
    if ($category === 'audio') {
        return 'audio';
    }
    if (in_array($category, ['utility', 'tool', 'tools', 'web'], true)) {
        return 'tools';
    }
    return 'experimental';
}

function hub_admin_market_pack_description(PDO $db, array $pack): string
{
    $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
    $packId = (string)($pack['id'] ?? $manifest['id'] ?? '');
    $fallback = (string)($manifest['description'] ?? $pack['description'] ?? '');
    return hub_i18n_seeded('pack.' . $packId . '.description', $fallback, null, $db);
}

function hub_admin_market_catalog(PDO $db, string $requestedCategory): array
{
    $activeCategory = hub_admin_market_category($requestedCategory);
    $categories = hub_admin_market_categories();
    $counts = array_fill_keys(array_keys($categories), 0);
    $rows = [];

    foreach (hub_list_packs() as $pack) {
        $category = hub_admin_market_category_for_manifest((array)($pack['manifest'] ?? []));
        $counts['all']++;
        $counts[$category]++;
        $pack['market_category'] = $category;
        $pack['purpose'] = hub_admin_market_pack_description($db, $pack);
        if ($activeCategory === 'all' || $activeCategory === $category) {
            $rows[] = $pack;
        }
    }

    return [
        'active_category' => $activeCategory,
        'categories' => $categories,
        'counts' => $counts,
        'packs' => $rows,
    ];
}

function hub_admin_market_runtime_badge_class(string $runtimeLevel): string
{
    $runtime = strtolower($runtimeLevel);
    if (str_contains($runtime, 'l5')) {
        return 'pack-badge pack-badge-ok';
    }
    if (str_contains($runtime, 'l4b')) {
        return 'pack-badge pack-badge-blue';
    }
    if (str_contains($runtime, 'l4a')) {
        return 'pack-badge pack-badge-purple';
    }
    if (str_contains($runtime, 'l4')) {
        return 'pack-badge pack-badge-purple';
    }
    if (str_contains($runtime, 'l3')) {
        return 'pack-badge pack-badge-warn';
    }

    return 'pack-badge pack-badge-muted';
}

function hub_admin_market_runtime_label(string $runtimeLevel): string
{
    $runtime = strtolower($runtimeLevel);
    if (str_contains($runtime, 'l5')) {
        return 'L5 可驗收';
    }
    if (str_contains($runtime, 'l4b')) {
        return 'L4b 真實推論';
    }
    if (str_contains($runtime, 'l4a')) {
        return 'L4a 模型檢查';
    }
    if (str_contains($runtime, 'l4')) {
        return 'L4 本機模型';
    }
    if (str_contains($runtime, 'l3-adapter')) {
        return 'L3 服務介接';
    }
    if (str_contains($runtime, 'l3')) {
        return 'L3 儲存掛載';
    }
    if (str_contains($runtime, 'l2')) {
        return 'L2 依賴檢查';
    }

    return 'Runtime 未分級';
}

function hub_admin_market_gpu_label(array $manifest, string $surface = 'packs'): array
{
    $hardware = is_array($manifest['hardware'] ?? null) ? $manifest['hardware'] : [];
    if ($surface === 'marketplace') {
        if (!empty($hardware['gpu_required'])) {
            return ['label' => hub_i18n_text('需要 GPU'), 'class' => 'hub-badge hub-badge-warn'];
        }
        if (!empty($hardware['gpu_supported'])) {
            return ['label' => hub_i18n_text('可用 GPU'), 'class' => 'hub-badge hub-badge-ok'];
        }
        return ['label' => hub_i18n_text('不使用 GPU'), 'class' => 'hub-badge hub-badge-muted'];
    }

    if (!empty($hardware['gpu_required'])) {
        return ['label' => '需要 GPU', 'class' => 'pack-badge pack-badge-blue'];
    }
    if (!empty($hardware['gpu_supported'])) {
        return !empty($hardware['cpu_fallback'])
            ? ['label' => '可退回 CPU', 'class' => 'pack-badge pack-badge-ok']
            : ['label' => '可用 GPU', 'class' => 'pack-badge pack-badge-warn'];
    }

    return ['label' => '不使用 GPU', 'class' => 'pack-badge pack-badge-muted'];
}

function hub_admin_market_model_label(PDO $db, array $manifest, string $surface = 'packs'): array
{
    foreach ((array)($manifest['async_jobs'] ?? []) as $job) {
        $runner = is_array($job) && is_array($job['runner'] ?? null) ? $job['runner'] : [];
        $assets = hub_pack_async_job_runner_asset_mounts($runner['asset_mounts'] ?? []);
        $fixedAssets = is_array($assets)
            ? array_values(array_filter($assets, static fn (array $asset): bool => !isset($asset['when'])))
            : [];
        if ($fixedAssets === []) {
            continue;
        }
        try {
            hub_pack_job_resolve_asset_mounts($db, ['asset_mounts' => $fixedAssets]);
            return $surface === 'marketplace'
                ? ['label' => hub_i18n_text('模型已就緒'), 'class' => 'hub-badge hub-badge-ok']
                : ['label' => '模型已就緒', 'class' => 'pack-badge pack-badge-ok'];
        } catch (Throwable) {
            return $surface === 'marketplace'
                ? ['label' => hub_i18n_text('缺少模型'), 'class' => 'hub-badge hub-badge-bad']
                : ['label' => '缺少模型', 'class' => 'pack-badge pack-badge-bad'];
        }
    }
    $schema = is_array($manifest['settings_schema'] ?? null) ? $manifest['settings_schema'] : [];
    $selectors = [];
    $required = false;
    foreach ($schema as $item) {
        if (!is_array($item) || !is_array($item['model_selector'] ?? null)) {
            continue;
        }
        $selector = $item['model_selector'];
        $selectors[] = $selector;
        $required = $required || !empty($item['required']);
        if ($surface !== 'marketplace' || trim((string)($item['default'] ?? '')) === '') {
            continue;
        }
        try {
            $status = hub_model_selector_status($db, $selector, trim((string)$item['default']));
            if (!empty($status['model_present']) || ((string)($selector['type'] ?? 'file') !== 'ollama_tag' && !empty($status['exists']))) {
                return ['label' => hub_i18n_text('模型已就緒'), 'class' => 'hub-badge hub-badge-ok'];
            }
        } catch (Throwable) {
        }
    }

    foreach ($selectors as $selector) {
        try {
            if (hub_model_selector_options($db, $selector) !== []) {
                return $surface === 'marketplace'
                    ? ['label' => hub_i18n_text('模型已就緒'), 'class' => 'hub-badge hub-badge-ok']
                    : ['label' => '模型已就緒', 'class' => 'pack-badge pack-badge-ok'];
            }
        } catch (Throwable) {
        }
    }

    if ($surface === 'marketplace') {
        return $required
            ? ['label' => hub_i18n_text('缺少模型'), 'class' => 'hub-badge hub-badge-bad']
            : ['label' => ($schema === [] ? hub_i18n_text('無模型需求') : hub_i18n_text('模型可選')), 'class' => 'hub-badge hub-badge-muted'];
    }
    if ($selectors === []) {
        return ['label' => '無模型需求', 'class' => 'pack-badge pack-badge-muted'];
    }
    return $required
        ? ['label' => '缺少模型', 'class' => 'pack-badge pack-badge-bad']
        : ['label' => '模型可選', 'class' => 'pack-badge pack-badge-warn'];
}

function hub_admin_market_endpoint_label(array $manifest): string
{
    $gateway = is_array($manifest['gateway'] ?? null) ? $manifest['gateway'] : [];
    $methods = array_map('strval', is_array($gateway['methods'] ?? null) ? $gateway['methods'] : []);
    return trim(($methods === [] ? '' : implode('/', $methods)) . ' ' . (string)($gateway['invoke_path'] ?? ''));
}

function hub_admin_market_runtime_modes(array $manifest): array
{
    $modes = is_array($manifest['runtime_modes'] ?? null) ? array_map('strval', $manifest['runtime_modes']) : [];
    if ($modes === []) {
        $modes[] = ((string)($manifest['runtime']['kind'] ?? 'docker') === 'internal_task') ? 'job' : 'service';
    }

    return array_values(array_unique($modes));
}

function hub_admin_market_installed_stats(PDO $db): array
{
    $stats = [];
    $sql = "SELECT pack_id, COUNT(*) AS installed_count, GROUP_CONCAT(mode, ', ') AS modes, MIN(id) AS first_service_id
            FROM services
            WHERE pack_id IS NOT NULL AND pack_id <> ''
            GROUP BY pack_id";
    foreach ($db->query($sql)->fetchAll() as $row) {
        $stats[(string)$row['pack_id']] = [
            'count' => (int)$row['installed_count'],
            'modes' => (string)($row['modes'] ?? ''),
            'first_service_id' => (int)($row['first_service_id'] ?? 0),
        ];
    }

    return $stats;
}

function hub_admin_market_readiness_label(PDO $db, string $packId, array $manifest): string
{
    if (!is_array($manifest['l5_contract'] ?? null)) {
        return '尚未宣告 L5 contract';
    }

    try {
        $readiness = hub_pack_l5_readiness($db, $packId);
        return (int)$readiness['pass_count'] . '/' . (int)$readiness['total_count'];
    } catch (Throwable $e) {
        return '無法讀取：' . $e->getMessage();
    }
}
