<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/admin_market.php';
require_once __DIR__ . '/_layout.php';

function hub_marketplace_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo hub_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hub_marketplace_worker_command(): string
{
    $script = HUB_ROOT . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'command_worker.php';

    return hub_platform_id() === 'windows'
        ? 'php ' . hub_powershell_single_quoted_literal($script) . ' --limit=5'
        : 'sudo php ' . escapeshellarg($script) . ' --limit=5';
}

function hub_marketplace_service_runtime_level(array $service): string
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));

    return (string)($pack['manifest']['runtime_level'] ?? '');
}

function hub_marketplace_service_endpoint(array $service): string
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));

    return hub_admin_market_endpoint_label(is_array($pack['manifest'] ?? null) ? $pack['manifest'] : []);
}

function hub_marketplace_service_status_label(string $status): string
{
    return [
        'queued' => hub_i18n_text('排隊中'),
        'starting' => hub_i18n_text('啟動中'),
        'running' => hub_i18n_text('執行中'),
        'stopped' => hub_i18n_text('已停止'),
        'unhealthy' => hub_i18n_text('異常'),
        'error' => hub_i18n_text('異常'),
        'failed' => hub_i18n_text('失敗'),
    ][$status] ?? hub_i18n_text('未知');
}

function hub_marketplace_service_status_class(string $status): string
{
    return [
        'running' => 'hub-badge-ok',
        'stopped' => 'hub-badge-muted',
        'queued' => 'hub-badge-warn',
        'starting' => 'hub-badge-warn',
        'unhealthy' => 'hub-badge-bad',
        'error' => 'hub-badge-bad',
        'failed' => 'hub-badge-bad',
    ][$status] ?? 'hub-badge-muted';
}

function hub_marketplace_install_env(string $packId, mixed $rawValues): array
{
    if (!is_array($rawValues)) {
        return [];
    }

    $values = [];
    foreach (hub_get_pack_settings_schema($packId) as $key => $item) {
        if (empty($item['install_option']) || !array_key_exists($key, $rawValues) || !is_scalar($rawValues[$key])) {
            continue;
        }
        $values[$key] = hub_validate_service_setting_value($item, (string)$rawValues[$key]);
    }

    return $values;
}

$db = hub_db();
$user = hub_require_system_admin($db);
hub_migrate($db);
$requestedView = (string)($_GET['view'] ?? 'market');
$view = in_array($requestedView, ['market', 'services'], true) ? $requestedView : 'market';
$category = hub_admin_market_category((string)($_GET['category'] ?? 'all'));
$isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$message = '';
$error = '';
$installedServiceId = 0;

if (($_GET['ajax'] ?? '') === 'readiness') {
    $packIdValue = $_GET['pack_id'] ?? null;
    if (
        !is_string($packIdValue)
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $packIdValue) !== 1
    ) {
        hub_marketplace_json(400, ['ok' => false, 'error' => hub_i18n_text('無效的套件 ID。')]);
    }
    $packId = $packIdValue;
    $pack = hub_get_pack($packId);
    if (!$pack || ($pack['status'] ?? '') !== 'ok') {
        hub_marketplace_json(404, ['ok' => false, 'error' => hub_i18n_text('找不到 HubPack。')]);
    }
    $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
    hub_marketplace_json(200, [
        'ok' => true,
        'pack_id' => $packId,
        'readiness' => hub_i18n_text(hub_admin_market_readiness_label($db, $packId, $manifest)),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $isServiceAction = array_key_exists('action', $_POST) || array_key_exists('service_id', $_POST);
    if (!hub_check_csrf(false)) {
        $error = hub_i18n_text('安全驗證失敗，請重新整理後再試。');
        if ($isAjax) {
            hub_marketplace_json(400, ['ok' => false, 'error' => $error]);
        }
    } elseif ($isServiceAction) {
        $view = 'services';
        $actionMap = [
            'build' => 'service_build',
            'start' => 'service_start',
            'stop' => 'service_stop',
            'restart' => 'service_restart',
            'rebuild' => 'service_rebuild',
            'remove' => 'service_remove',
            'refresh' => 'service_health_check',
            'provision_pascal_ckip' => 'whisper_pascal_ckip_provision',
            'provision_paligemma2' => 'paligemma2_provision',
            'accept_paligemma2' => 'paligemma2_acceptance',
        ];
        $serviceIdValue = (string)($_POST['service_id'] ?? '');
        $serviceId = preg_match('/^[1-9][0-9]*$/D', $serviceIdValue) === 1 ? (int)$serviceIdValue : 0;
        $action = (string)($_POST['action'] ?? '');
        $service = $serviceId > 0 ? hub_get_service($db, $serviceId) : null;
        if (
            !$service
            || !isset($actionMap[$action])
            || ($action === 'provision_pascal_ckip' && hub_whisper_pascal_ckip_provisioning_plan($service) === null)
            || ($action === 'provision_paligemma2' && hub_paligemma2_provisioning_plan($service) === null)
            || ($action === 'accept_paligemma2' && hub_paligemma2_provisioning_plan($service) === null)
        ) {
            $error = hub_i18n_text('無效的服務操作。');
            if ($isAjax) {
                hub_marketplace_json(400, ['ok' => false, 'error' => $error]);
            }
        } else {
            $queueAction = $actionMap[$action];
            $removalError = hub_i18n_text('服務尚未停止或仍有背景工作，暫時無法移除。');
            try {
                $removalBlocked = $action === 'remove' && hub_service_removal_block_reason($db, $service) !== null;
                if ($removalBlocked) {
                    $error = $removalError;
                    if ($isAjax) {
                        hub_marketplace_json(409, ['ok' => false, 'error' => $error]);
                    }
                } else {
                    $jobId = hub_enqueue_command_job(
                        $db,
                        $queueAction,
                        (int)$service['id'],
                        ['reason' => 'admin_click'],
                        (int)$user['id'],
                        $_SERVER['REMOTE_ADDR'] ?? null
                    );
                    $message = hub_i18n_text('已排入背景工作 #') . $jobId . hub_i18n_text('，請等待 command worker 執行。');
                    if ($isAjax) {
                        $job = hub_command_job_status_payload($db, $jobId);
                        if ($job === null) {
                            hub_marketplace_json(500, ['ok' => false, 'error' => hub_i18n_text('無法讀取背景工作狀態。')]);
                        }
                        $job['action_label'] = hub_i18n_text(hub_command_action_label((string)$job['action']));
                        $job['status_label'] = hub_i18n_text(hub_command_status_label((string)$job['status']));
                        hub_marketplace_json(200, [
                            'ok' => true,
                            'message' => $message,
                            'job' => $job,
                        ]);
                    }
                }
            } catch (PDOException) {
                $error = hub_i18n_text('服務操作暫時無法處理，請稍後再試。');
                if ($isAjax) {
                    hub_marketplace_json(503, ['ok' => false, 'error' => $error]);
                }
            } catch (RuntimeException $e) {
                $queueAdmissionConflict = in_array($e->getMessage(), [
                    'Cannot enqueue service removal while another service command is active.',
                    'Cannot enqueue a service command while removal is active.',
                ], true);
                $error = $queueAdmissionConflict
                    ? ($action === 'remove' ? $removalError : hub_i18n_text('服務操作與背景工作衝突，請稍後再試。'))
                    : hub_i18n_text('無法排入背景工作，請重新整理後再試。');
                if ($isAjax) {
                    hub_marketplace_json($queueAdmissionConflict ? 409 : 500, ['ok' => false, 'error' => $error]);
                }
            } catch (Throwable) {
                $error = hub_i18n_text('無法排入背景工作，請重新整理後再試。');
                if ($isAjax) {
                    hub_marketplace_json(500, ['ok' => false, 'error' => $error]);
                }
            }
        }
    } else {
        try {
            $packId = (string)($_POST['pack_id'] ?? '');
            $result = hub_install_pack($db, $packId, [
                'service_key' => trim((string)($_POST['service_key'] ?? '')),
                'name' => trim((string)($_POST['name'] ?? '')),
                'mode' => trim((string)($_POST['mode'] ?? '')),
                'port_mode' => (string)($_POST['port_mode'] ?? 'auto'),
                'local_port' => trim((string)($_POST['local_port'] ?? '')),
                'environment' => (string)($_POST['environment'] ?? 'production'),
                'hot_reload' => !empty($_POST['hot_reload']),
                'env' => hub_marketplace_install_env($packId, $_POST['install_setting'] ?? []),
                'provision_runner' => false,
            ]);
            $installedServiceId = (int)$result['service']['id'];
            $jobId = hub_enqueue_command_job(
                $db,
                'service_install',
                $installedServiceId,
                ['reason' => 'marketplace_install'],
                (int)$user['id'],
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            $message = hub_i18n_text('已建立 Service Instance：') . $result['service']['service_key']
                . hub_i18n_text('；已排入背景工作 #') . $jobId . '。';
            $demos = $result['edge_tts_demos'] ?? null;
            if (is_array($demos) && isset($demos['succeeded'], $demos['failed'])) {
                $message .= hub_i18n_text('語音示範成功 ') . (int)$demos['succeeded']
                    . hub_i18n_text(' 個，失敗 ') . (int)$demos['failed'] . hub_i18n_text(' 個。');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$dictionary = [
    'running' => hub_i18n_text('執行中'),
    'stopped' => hub_i18n_text('已停止'),
    'unknown' => hub_i18n_text('未知'),
    'queued_status' => hub_i18n_text('排隊中'),
    'starting' => hub_i18n_text('啟動中'),
    'unhealthy' => hub_i18n_text('異常'),
    'failed' => hub_i18n_text('失敗'),
    'health_ok' => hub_i18n_text('健康正常'),
    'health_checking' => hub_i18n_text('健康檢查中'),
    'health_failed' => hub_i18n_text('健康異常'),
    'enabled' => hub_i18n_text('已啟用'),
    'disabled' => hub_i18n_text('已停用'),
    'restart_required' => hub_i18n_text('需重啟'),
    'restart_applied' => hub_i18n_text('設定已套用'),
    'poll_failed' => hub_i18n_text('讀取背景工作狀態失敗，請稍後重試或重新整理。'),
    'summary_failed' => hub_i18n_text('讀取服務摘要失敗，請稍後重試。'),
    'action_failed' => hub_i18n_text('操作失敗，請重新整理後再試。'),
    'queued' => hub_i18n_text('已排入背景工作。'),
    'job_failed_feedback' => hub_i18n_text('背景工作失敗，已保留工作輸出。'),
    'job_cancelled_feedback' => hub_i18n_text('背景工作已取消，已保留工作輸出。'),
    'job_timeout_feedback' => hub_i18n_text('背景工作逾時，已保留工作輸出。'),
    'action_service_start' => hub_i18n_text('啟動服務'),
    'action_service_stop' => hub_i18n_text('停止服務'),
    'action_service_restart' => hub_i18n_text('重啟服務'),
    'action_service_build' => hub_i18n_text('建置服務'),
    'action_service_rebuild' => hub_i18n_text('重新建置'),
    'action_service_remove' => hub_i18n_text('移除服務'),
    'action_service_health_check' => hub_i18n_text('健康檢查'),
    'action_service_install' => hub_i18n_text('安裝服務'),
    'action_whisper_pascal_ckip_provision' => hub_i18n_text('準備 CKIP 字幕資產'),
    'action_paligemma2_provision' => hub_i18n_text('準備 PaliGemma 2 模型'),
    'action_paligemma2_acceptance' => hub_i18n_text('執行 PaliGemma 2 CUDA 驗收'),
    'remove_confirm' => hub_i18n_text('確定移除此服務嗎？服務設定將刪除，模型與既有產物會保留。'),
    'job_status_queued' => hub_i18n_text('排隊中'),
    'job_status_running' => hub_i18n_text('執行中'),
    'job_status_success' => hub_i18n_text('成功'),
    'job_status_failed' => hub_i18n_text('失敗'),
    'job_status_cancelled' => hub_i18n_text('已取消'),
    'job_status_timeout' => hub_i18n_text('逾時'),
    'refreshing' => hub_i18n_text('刷新中'),
    'refresh' => hub_i18n_text('刷新'),
    'readiness_failed' => hub_i18n_text('讀取失敗'),
    'required_fields' => hub_i18n_text('請完成標示的必填欄位。'),
    'copied' => hub_i18n_text('API URL 已複製。'),
    'copy_failed' => hub_i18n_text('無法自動複製，請手動複製。'),
];

hub_admin_header(hub_i18n_text('HubPack 套件'), $user);
?>
<link rel="stylesheet" href="../assets/css/admin-market.css">
<div class="market-page" data-market-view="<?= hub_h($view) ?>">
    <nav class="workspace-tabs" aria-label="<?= hub_h(hub_i18n_text('安裝套件工作區')) ?>">
        <a class="workspace-tab<?= $view === 'market' ? ' is-active' : '' ?>"
           href="marketplace.php?view=market&amp;category=<?= hub_h($category) ?>">
            <?= hub_h(hub_i18n_text('套件市集')) ?>
        </a>
        <a class="workspace-tab<?= $view === 'services' ? ' is-active' : '' ?>"
           href="marketplace.php?view=services">
            <?= hub_h(hub_i18n_text('已安裝服務')) ?>
        </a>
    </nav>

    <div id="service-message" class="notice" role="status" aria-live="polite" aria-atomic="true"<?= $message === '' ? ' style="display:none"' : '' ?>>
        <?= hub_h($message) ?>
        <?php if ($installedServiceId > 0): ?>
            <a href="service_settings.php?service_id=<?= $installedServiceId ?>"><?= hub_h(hub_i18n_text('設定服務')) ?></a>
        <?php endif; ?>
    </div>
    <?php if ($error !== ''): ?><div class="error" role="alert"><?= hub_h($error) ?></div><?php endif; ?>

    <?php if ($view === 'market'): ?>
        <?php
        $catalog = hub_admin_market_catalog($db, $category);
        $installed = hub_admin_market_installed_stats($db);
        $categories = $catalog['categories'];
        $counts = $catalog['counts'];
        $preflightLabels = [
            'docker' => 'Docker',
            'docker_compose' => 'Docker Compose',
            'nvidia_smi' => 'GPU',
            'docker_gpus' => 'NVIDIA Container',
            'vram' => 'VRAM',
            'compute_capability' => 'Compute Capability',
            'storage' => 'Storage',
        ];
        ?>
        <header class="market-head">
            <div>
                <h1><?= hub_h(hub_i18n_text('本機 HubPack 安裝目錄')) ?></h1>
                <p><?= hub_h(hub_i18n_text('檢視 Pack 規格與 Preflight 檢查結果，設定後安裝為本機服務。')) ?></p>
            </div>
            <strong><?= hub_h(hub_i18n_text('共')) ?> <?= (int)$counts['all'] ?> <?= hub_h(hub_i18n_text('個 Pack')) ?></strong>
        </header>

        <nav class="market-categories" aria-label="<?= hub_h(hub_i18n_text('HubPack 分類')) ?>">
            <?php foreach ($categories as $categoryKey => $categoryLabel): ?>
                <a class="market-category<?= $catalog['active_category'] === $categoryKey ? ' is-active' : '' ?>"
                   data-market-category="<?= hub_h((string)$categoryKey) ?>"
                   data-market-count="<?= (int)($counts[$categoryKey] ?? 0) ?>"
                   href="marketplace.php?view=market&amp;category=<?= hub_h((string)$categoryKey) ?>">
                    <span><?= hub_h(hub_i18n_text((string)$categoryLabel)) ?></span>
                    <strong><?= (int)($counts[$categoryKey] ?? 0) ?></strong>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($catalog['packs'] === []): ?>
            <div class="market-empty"><?= hub_h(hub_i18n_text('此分類目前沒有 HubPack。')) ?></div>
        <?php else: ?>
            <section class="pack-grid hub-card-grid" aria-label="<?= hub_h(hub_i18n_text('HubPack 清單')) ?>">
                <?php foreach ($catalog['packs'] as $pack): ?>
                    <?php
                    $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
                    $packId = (string)($pack['id'] ?? $manifest['id'] ?? '');
                    $runtimeLevel = (string)($manifest['runtime_level'] ?? '');
                    $targetLevel = (string)($manifest['target_level'] ?? '');
                    $defaultKey = (string)($manifest['install']['default_service_key'] ?? ($packId . '-main'));
                    $defaultMode = (string)($manifest['default_mode'] ?? '');
                    $defaultPort = (string)($manifest['service']['default_local_port'] ?? '');
                    $endpoint = hub_admin_market_endpoint_label($manifest);
                    $gpu = hub_admin_market_gpu_label($manifest, 'marketplace');
                    $model = hub_admin_market_model_label($db, $manifest, 'marketplace');
                    $stats = $installed[$packId] ?? ['count' => 0, 'modes' => '', 'first_service_id' => 0];
                    $preflight = hub_pack_preflight($db, $manifest);
                    $readiness = hub_i18n_text(hub_admin_market_readiness_label($db, $packId, $manifest));
                    $schema = is_array($manifest['settings_schema'] ?? null) ? $manifest['settings_schema'] : [];
                    $installOptions = array_filter(
                        hub_get_pack_settings_schema($packId),
                        static fn (array $item): bool => !empty($item['install_option'])
                    );
                    $modelSelectors = array_values(array_filter(
                        $schema,
                        static fn (mixed $item): bool => is_array($item) && is_array($item['model_selector'] ?? null)
                    ));
                    $computeLabel = !empty($manifest['hardware']['gpu_required'])
                        ? hub_i18n_text('GPU')
                        : (!empty($manifest['hardware']['gpu_supported']) ? hub_i18n_text('CPU / GPU') : hub_i18n_text('CPU'));
                    $firstServiceId = (int)($stats['first_service_id'] ?? 0);
                    ?>
                    <article class="pack-card hub-card<?= ($pack['status'] ?? '') === 'ok' ? '' : ' pack-card-blocked' ?>">
                        <header class="pack-card-head">
                            <div>
                                <h2><?= hub_h((string)($manifest['name'] ?? $packId)) ?></h2>
                                <p>pack_id: <code><?= hub_h($packId) ?></code></p>
                            </div>
                        </header>
                        <p class="pack-purpose"><?= hub_h((string)($pack['purpose'] ?? '')) ?></p>
                        <div class="pack-badges" aria-label="<?= hub_h(hub_i18n_text('套件摘要')) ?>">
                            <span class="<?= hub_h(hub_admin_market_runtime_badge_class($runtimeLevel)) ?>"><?= hub_h(hub_i18n_text(hub_admin_market_runtime_label($runtimeLevel))) ?></span>
                            <span class="pack-badge pack-badge-blue"><?= hub_h($computeLabel) ?></span>
                            <span class="<?= hub_h((string)$model['class']) ?>"><?= hub_h((string)$model['label']) ?></span>
                            <span class="pack-badge <?= (int)$stats['count'] > 0 ? 'pack-badge-ok' : 'pack-badge-muted' ?>">
                                <?= hub_h(hub_i18n_text('已安裝')) ?> <?= (int)$stats['count'] ?>
                            </span>
                            <span class="pack-badge pack-badge-muted">
                                <?= hub_h(hub_i18n_text('準備狀態')) ?>:
                                <span class="pack-readiness-value" data-pack-id="<?= hub_h($packId) ?>"><?= hub_h($readiness) ?></span>
                            </span>
                            <button class="pack-readiness-refresh" type="button" data-pack-id="<?= hub_h($packId) ?>"><?= hub_h(hub_i18n_text('刷新')) ?></button>
                        </div>

                        <form class="pack-install-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                            <input type="hidden" name="pack_id" value="<?= hub_h($packId) ?>">
                            <div class="pack-actions hub-actions">
                                <?php if (($pack['status'] ?? '') === 'ok'): ?>
                                    <button class="primary" type="submit"><?= hub_h(hub_i18n_text('安裝為服務')) ?></button>
                                <?php else: ?>
                                    <button type="button" disabled><?= hub_h(hub_i18n_text('無法安裝')) ?></button>
                                <?php endif; ?>
                                <?php if ($firstServiceId > 0): ?>
                                    <a class="button market-primary" href="service_settings.php?service_id=<?= $firstServiceId ?>"><?= hub_h(hub_i18n_text('設定')) ?></a>
                                <?php endif; ?>
                                <a class="button" href="api_docs.php"><?= hub_h(hub_i18n_text('查看 API 文件')) ?></a>
                                <a class="button" href="benchmarks.php"><?= hub_h(hub_i18n_text('Benchmark 測試')) ?></a>
                                <a class="button" href="pack_readiness.php?pack_id=<?= urlencode($packId) ?>"><?= hub_h(hub_i18n_text('完整準備狀態')) ?></a>
                                <a class="button" href="marketplace.php?view=services"><?= hub_h(hub_i18n_text('已安裝服務')) ?></a>
                            </div>

                            <details class="pack-details">
                                <summary><?= hub_h(hub_i18n_text('安裝選項與技術詳細資料')) ?></summary>
                                <?php if (($pack['status'] ?? '') === 'ok'): ?>
                                    <div class="pack-install-fields">
                                        <label><?= hub_h(hub_i18n_text('服務 key')) ?> / service_key
                                            <input name="service_key" value="<?= hub_h($defaultKey) ?>" required>
                                        </label>
                                        <label><?= hub_h(hub_i18n_text('顯示名稱')) ?>
                                            <input name="name" value="<?= hub_h((string)($manifest['name'] ?? '')) ?>" required>
                                        </label>
                                        <label><?= hub_h(hub_i18n_text('API 模式')) ?> / mode
                                            <input name="mode" value="<?= hub_h($defaultMode) ?>" required>
                                        </label>
                                        <label><?= hub_h(hub_i18n_text('本機 port 模式')) ?>
                                            <select name="port_mode">
                                                <option value="auto">auto</option>
                                                <option value="manual">manual</option>
                                            </select>
                                        </label>
                                        <label><?= hub_h(hub_i18n_text('本機 port')) ?>
                                            <input name="local_port" value="<?= hub_h($defaultPort) ?>">
                                        </label>
                                        <label><?= hub_h(hub_i18n_text('環境')) ?>
                                            <select name="environment">
                                                <option value="production">production</option>
                                                <option value="development">development</option>
                                            </select>
                                        </label>
                                        <label class="pack-check">
                                            <input name="hot_reload" type="checkbox" value="1"> hot_reload
                                        </label>
                                        <?php foreach ($installOptions as $settingKey => $setting): ?>
                                            <?php
                                            $settingType = (string)($setting['type'] ?? 'text');
                                            $settingRequired = !empty($setting['required']) ? ' required' : '';
                                            $settingLabel = hub_i18n_text((string)($setting['label'] ?? $settingKey));
                                            ?>
                                            <label><?= hub_h($settingLabel) ?> <code><?= hub_h($settingKey) ?></code>
                                                <?php if ($settingType === 'select'): ?>
                                                    <select name="install_setting[<?= hub_h($settingKey) ?>]"<?= $settingRequired ?>>
                                                        <?php foreach ((array)($setting['options'] ?? []) as $option): ?>
                                                            <option value="<?= hub_h((string)$option) ?>"<?= (string)($setting['default'] ?? '') === (string)$option ? ' selected' : '' ?>><?= hub_h(hub_i18n_text((string)($setting['option_labels'][$option] ?? $option))) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php elseif ($settingType === 'boolean'): ?>
                                                    <input name="install_setting[<?= hub_h($settingKey) ?>]" type="checkbox" value="1"<?= (string)($setting['default'] ?? '') === '1' ? ' checked' : '' ?>>
                                                <?php else: ?>
                                                    <input name="install_setting[<?= hub_h($settingKey) ?>]" type="<?= $settingType === 'secret' ? 'password' : (in_array($settingType, ['integer', 'number'], true) ? 'number' : 'text') ?>" value="<?= $settingType === 'secret' ? '' : hub_h((string)($setting['default'] ?? '')) ?>"<?= isset($setting['min']) ? ' min="' . hub_h((string)$setting['min']) . '"' : '' ?><?= isset($setting['max']) ? ' max="' . hub_h((string)$setting['max']) . '"' : '' ?><?= $settingRequired ?>>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <dl class="market-spec hub-meta">
                                    <dt><?= hub_h(hub_i18n_text('套件名稱')) ?></dt>
                                    <dd><?= hub_h((string)($manifest['name'] ?? $packId)) ?></dd>
                                    <dt><?= hub_h(hub_i18n_text('套件 ID')) ?></dt>
                                    <dd>pack_id: <code><?= hub_h($packId) ?></code></dd>
                                    <dt><?= hub_h(hub_i18n_text('版本')) ?></dt>
                                    <dd><code><?= hub_h((string)($manifest['version'] ?? '')) ?></code></dd>
                                    <dt><?= hub_h(hub_i18n_text('類型')) ?></dt>
                                    <dd><code><?= hub_h((string)($manifest['type'] ?? '')) ?></code></dd>
                                    <dt><?= hub_h(hub_i18n_text('執行層級')) ?> / <code>runtime_level</code></dt>
                                    <dd><code><?= hub_h($runtimeLevel) ?></code></dd>
                                    <dt><?= hub_h(hub_i18n_text('目標層級')) ?> / <code>target_level</code></dt>
                                    <dd><code><?= hub_h($targetLevel) ?></code></dd>
                                    <dt><?= hub_h(hub_i18n_text('預設 mode')) ?></dt>
                                    <dd><code><?= hub_h($defaultMode) ?></code></dd>
                                    <dt><?= hub_h(hub_i18n_text('API endpoint')) ?></dt>
                                    <dd><code><?= hub_h($endpoint) ?></code></dd>
                                    <dt><code>execution_type</code></dt>
                                    <dd><code><?= hub_h((string)($manifest['execution_type'] ?? '')) ?></code></dd>
                                    <dt><?= hub_h(hub_i18n_text('GPU 需求')) ?></dt>
                                    <dd><?= hub_h((string)$gpu['label']) ?></dd>
                                    <dt><?= hub_h(hub_i18n_text('模型需求')) ?></dt>
                                    <dd><?= hub_h((string)$model['label']) ?></dd>
                                    <dt><?= hub_h(hub_i18n_text('已安裝服務數')) ?></dt>
                                    <dd><?= (int)$stats['count'] ?></dd>
                                    <dt><?= hub_h(hub_i18n_text('安裝狀態')) ?></dt>
                                    <dd><?= hub_h(($pack['status'] ?? '') === 'ok' ? hub_i18n_text('可安裝') : hub_i18n_text('pack 驗證失敗')) ?></dd>
                                    <?php foreach ($modelSelectors as $selectorItem): ?>
                                        <dt><code>model_selector</code></dt>
                                        <dd>
                                            <code><?= hub_h((string)($selectorItem['key'] ?? '')) ?></code>
                                            <pre class="market-code"><?= hub_h(hub_json_encode($selectorItem['model_selector'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                                        </dd>
                                    <?php endforeach; ?>
                                    <dt><code>preflight</code></dt>
                                    <dd>
                                        <?php if ((int)$preflight['summary']['total'] === 0): ?>
                                            <?= hub_h(hub_i18n_text('無檢查項目')) ?>
                                        <?php else: ?>
                                            <strong class="<?= hub_h(hub_status_class((string)$preflight['summary']['status'])) ?>">
                                                <?= hub_h(hub_i18n_text(hub_status_label((string)$preflight['summary']['status']))) ?>
                                            </strong>
                                            <?php if ((string)$preflight['snapshot_at'] === ''): ?>
                                                <p><code>php scripts/collect_host_metrics.php --force</code></p>
                                            <?php endif; ?>
                                            <ul class="preflight-list">
                                                <?php foreach ($preflight['checks'] as $check): ?>
                                                    <li>
                                                        <code><?= hub_h((string)($preflightLabels[$check['key']] ?? $check['key'])) ?></code>
                                                        <strong class="<?= hub_h(hub_status_class((string)$check['status'])) ?>"><?= hub_h(hub_i18n_text(hub_status_label((string)$check['status']))) ?></strong>
                                                        <span><?= hub_h((string)$check['detail']) ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </dd>
                                    <dt><?= hub_h(hub_i18n_text('驗證錯誤')) ?></dt>
                                    <dd>
                                        <?php if (!empty($pack['errors'])): ?>
                                            <pre class="market-code market-code-error"><?= hub_h(implode("\n", $pack['errors'])) ?></pre>
                                        <?php else: ?>
                                            <?= hub_h(hub_i18n_text('無')) ?>
                                        <?php endif; ?>
                                    </dd>
                                </dl>
                            </details>
                        </form>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <?php
        $services = hub_list_services($db);
        $jobs = hub_list_command_jobs($db, 50);
        $queuedJobCount = count(array_filter($jobs, static fn (array $job): bool => $job['status'] === 'queued'));
        $summary = [
            'total' => count($services),
            'running' => count(array_filter(
                $services,
                static fn (array $service): bool => (string)($service['runtime_status'] ?? $service['status']) === 'running'
            )),
            'stopped' => count(array_filter(
                $services,
                static fn (array $service): bool => (string)($service['runtime_status'] ?? $service['status']) !== 'running'
            )),
            'disabled' => count(array_filter($services, static fn (array $service): bool => (int)$service['enabled'] !== 1)),
            'active_jobs' => count(array_filter($jobs, static fn (array $job): bool => in_array((string)$job['status'], ['queued', 'running'], true))),
            'failed_jobs' => count(array_filter($jobs, static fn (array $job): bool => (string)$job['status'] === 'failed')),
        ];
        $activeJobsByService = [];
        $lastJobsByService = [];
        $lastHealthJobsByService = [];
        foreach ($jobs as $job) {
            $serviceId = (int)($job['service_id'] ?? 0);
            if ($serviceId > 0 && !isset($lastJobsByService[$serviceId])) {
                $lastJobsByService[$serviceId] = $job;
            }
            if ($serviceId > 0 && (string)$job['action'] === 'service_health_check' && !isset($lastHealthJobsByService[$serviceId])) {
                $lastHealthJobsByService[$serviceId] = $job;
            }
            if ($serviceId > 0 && in_array((string)$job['status'], ['queued', 'running'], true) && !isset($activeJobsByService[$serviceId])) {
                $activeJobsByService[$serviceId] = $job;
            }
        }
        ?>
        <header class="market-head">
            <div>
                <h1><?= hub_h(hub_i18n_text('已安裝服務')) ?></h1>
                <p><?= hub_h(hub_i18n_text('服務操作只會排入背景工作，由 command worker 實際執行。')) ?></p>
            </div>
            <a class="button" href="log_explorer.php?tab=jobs"><?= hub_h(hub_i18n_text('查看背景工作')) ?></a>
        </header>

        <?php if ($queuedJobCount > 0): ?>
            <div class="notice">
                <?= hub_h(hub_i18n_text('目前有背景工作排隊中。可先在主機執行：')) ?>
                <code><?= hub_h(hub_marketplace_worker_command()) ?></code>
            </div>
        <?php endif; ?>

        <section class="service-summary" aria-label="<?= hub_h(hub_i18n_text('服務摘要')) ?>">
            <?php foreach ([
                'total' => hub_i18n_text('全部服務'),
                'running' => hub_i18n_text('執行中'),
                'stopped' => hub_i18n_text('已停止'),
                'disabled' => hub_i18n_text('已停用'),
                'active_jobs' => hub_i18n_text('背景工作執行中'),
                'failed_jobs' => hub_i18n_text('最近失敗工作'),
            ] as $summaryKey => $label): ?>
                <div class="service-stat" data-service-summary="<?= hub_h($summaryKey) ?>">
                    <span><?= hub_h($label) ?></span>
                    <strong data-service-summary-value><?= (int)$summary[$summaryKey] ?></strong>
                </div>
            <?php endforeach; ?>
        </section>

        <?php if ($services === []): ?>
            <div class="market-empty"><?= hub_h(hub_i18n_text('目前沒有已安裝服務。')) ?></div>
        <?php else: ?>
            <section class="service-grid" aria-label="<?= hub_h(hub_i18n_text('服務列表')) ?>">
                <?php foreach ($services as $service): ?>
                    <?php
                    $serviceId = (int)$service['id'];
                    $activeJob = $activeJobsByService[$serviceId] ?? null;
                    $serviceHasActiveJob = $activeJob !== null || hub_service_has_active_command_job($db, $serviceId);
                    $lastJob = $lastJobsByService[$serviceId] ?? null;
                    $healthJob = $lastHealthJobsByService[$serviceId] ?? null;
                    $actualState = (string)($service['runtime_status'] ?? $service['status'] ?? 'stopped');
                    $state = $actualState;
                    if ($activeJob && (string)$activeJob['status'] === 'queued') {
                        $state = 'queued';
                    } elseif ($activeJob && (string)$activeJob['status'] === 'running') {
                        $state = (string)$activeJob['action'] === 'service_start' ? 'starting' : 'running';
                    } elseif ($state === 'error') {
                        $state = 'failed';
                    }
                    if (!$healthJob) {
                        $healthState = ['label' => hub_i18n_text('健康未檢查'), 'class' => 'hub-badge-muted'];
                    } elseif (in_array((string)$healthJob['status'], ['queued', 'running'], true)) {
                        $healthState = ['label' => hub_i18n_text('健康檢查中'), 'class' => 'hub-badge-warn'];
                    } elseif ((string)$healthJob['status'] === 'success' && $actualState === 'running') {
                        $healthState = ['label' => hub_i18n_text('健康正常'), 'class' => 'hub-badge-ok'];
                    } else {
                        $healthState = ['label' => hub_i18n_text('健康異常'), 'class' => 'hub-badge-bad'];
                    }
                    $runtimeLevel = hub_marketplace_service_runtime_level($service);
                    $pascalCkipPlan = hub_whisper_pascal_ckip_provisioning_plan($service);
                    $paligemmaProvisionPlan = hub_paligemma2_provisioning_plan($service);
                    $endpoint = hub_marketplace_service_endpoint($service);
                    $apiUrl = '../api.php?mode=' . rawurlencode((string)$service['mode']);
                    $lastError = $lastJob && (string)$lastJob['status'] === 'failed'
                        ? trim((string)($lastJob['error_message'] ?? ''))
                        : '';
                    ?>
                    <article class="service-card"
                             data-service-row-id="<?= $serviceId ?>"
                             data-service-actual-status="<?= hub_h($actualState) ?>"
                             data-service-enabled="<?= (int)$service['enabled'] === 1 ? '1' : '0' ?>"
                             data-service-restart-required="<?= (int)($service['restart_required'] ?? 0) === 1 ? '1' : '0' ?>">
                        <header class="service-card-head">
                            <div>
                                <h2><?= hub_h((string)$service['name']) ?></h2>
                                <p>service_key: <code><?= hub_h((string)($service['service_key'] ?? '')) ?></code></p>
                            </div>
                            <span data-service-status class="hub-badge <?= hub_h(hub_marketplace_service_status_class($state)) ?>">
                                <span data-service-status-label><?= hub_h(hub_marketplace_service_status_label($state)) ?></span>
                            </span>
                        </header>

                        <div class="service-badges">
                            <span data-service-status-summary data-service-status class="hub-badge <?= hub_h(hub_marketplace_service_status_class($state)) ?>">
                                <?= hub_h(hub_i18n_text('服務狀態')) ?>:
                                <span data-service-status-label><?= hub_h(hub_marketplace_service_status_label($state)) ?></span>
                            </span>
                            <span data-service-enabled-badge class="hub-badge <?= (int)$service['enabled'] === 1 ? 'hub-badge-ok' : 'hub-badge-muted' ?>">
                                <span data-service-enabled-label><?= hub_h((int)$service['enabled'] === 1 ? hub_i18n_text('已啟用') : hub_i18n_text('已停用')) ?></span>
                            </span>
                            <span data-service-health class="hub-badge <?= hub_h((string)$healthState['class']) ?>">
                                <span data-service-health-label><?= hub_h((string)$healthState['label']) ?></span>
                            </span>
                            <span data-service-restart-badge class="hub-badge <?= (int)($service['restart_required'] ?? 0) === 1 ? 'hub-badge-warn' : 'hub-badge-ok' ?>">
                                <span data-service-restart-label>
                                    <?= hub_h((int)($service['restart_required'] ?? 0) === 1 ? hub_i18n_text('需重啟') : hub_i18n_text('設定已套用')) ?>
                                </span>
                            </span>
                        </div>

                        <?php if ($lastError !== ''): ?>
                            <details class="service-required-error">
                                <summary><?= hub_h(hub_i18n_text('最近失敗工作')) ?></summary>
                                <p><?= hub_h($lastError) ?></p>
                            </details>
                        <?php endif; ?>

                        <form class="service-action-form" method="post" data-service-refresh-form="<?= $serviceId ?>">
                            <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                            <input type="hidden" name="service_id" value="<?= $serviceId ?>">
                            <div class="service-operations">
                                <?php if ($actualState !== 'running'): ?>
                                    <button class="primary" name="action" value="start" type="submit"><?= hub_h(hub_i18n_text('啟動')) ?></button>
                                <?php endif; ?>
                                <?php if ($actualState !== 'stopped'): ?>
                                    <button class="danger" name="action" value="stop" type="submit"><?= hub_h(hub_i18n_text('停止')) ?></button>
                                <?php endif; ?>
                                <button name="action" value="restart" type="submit"><?= hub_h(hub_i18n_text('重啟')) ?></button>
                                <button name="action" value="build" type="submit"><?= hub_h(hub_i18n_text('建置')) ?></button>
                                <button name="action" value="rebuild" type="submit"><?= hub_h(hub_i18n_text('重新建置')) ?></button>
                                <button name="action" value="refresh" type="submit"><?= hub_h(hub_i18n_text('健康檢查')) ?></button>
                                <?php if ($pascalCkipPlan !== null): ?>
                                    <button name="action" value="provision_pascal_ckip" type="submit"><?= hub_h(hub_i18n_text('準備 CKIP 字幕資產')) ?></button>
                                <?php endif; ?>
                                <?php if ($paligemmaProvisionPlan !== null): ?>
                                    <button name="action" value="provision_paligemma2" type="submit"><?= hub_h(hub_i18n_text('準備 PaliGemma 2 模型')) ?></button>
                                    <button name="action" value="accept_paligemma2" type="submit"><?= hub_h(hub_i18n_text('執行 PaliGemma 2 CUDA 驗收')) ?></button>
                                <?php endif; ?>
                                <?php if ($actualState === 'stopped' && !$serviceHasActiveJob): ?>
                                    <button class="danger" name="action" value="remove" type="submit"><?= hub_h(hub_i18n_text('移除')) ?></button>
                                <?php endif; ?>
                            </div>
                        </form>

                        <div class="service-links">
                            <a class="button market-primary" href="service_settings.php?service_id=<?= $serviceId ?>"><?= hub_h(hub_i18n_text('設定')) ?></a>
                            <a class="button" href="service_logs.php?id=<?= $serviceId ?>"><?= hub_h(hub_i18n_text('服務記錄')) ?></a>
                            <a class="button" href="log_explorer.php?service_id=<?= $serviceId ?>"><?= hub_h(hub_i18n_text('API 記錄')) ?></a>
                            <a class="button" href="log_explorer.php?tab=jobs&amp;service_id=<?= $serviceId ?>"><?= hub_h(hub_i18n_text('背景工作')) ?></a>
                            <a class="button" href="benchmarks.php"><?= hub_h(hub_i18n_text('Benchmark 測試')) ?></a>
                            <a class="button" href="playground.php?mode=<?= urlencode((string)$service['mode']) ?>"><?= hub_h(hub_i18n_text('API 測試場')) ?></a>
                            <button type="button" data-copy-target="service-api-url-<?= $serviceId ?>"><?= hub_h(hub_i18n_text('複製 API URL')) ?></button>
                        </div>

                        <div class="service-job" data-service-id="<?= $serviceId ?>" data-job-id="<?= $activeJob ? (int)$activeJob['id'] : '' ?>"<?= $activeJob ? '' : ' style="display:none"' ?>>
                            <div class="job-progress"><span style="width: <?= $activeJob ? (int)$activeJob['progress'] : 0 ?>%"></span></div>
                            <div class="job-meta">
                                #<span class="job-id"><?= $activeJob ? (int)$activeJob['id'] : '' ?></span>
                                <span class="job-progress-text"><?= $activeJob ? (int)$activeJob['progress'] : 0 ?></span>%
                                <code class="job-stage"><?= hub_h((string)($activeJob['stage'] ?? '')) ?></code>
                                <span class="job-message"><?= hub_h((string)($activeJob['current_message'] ?? '')) ?></span>
                            </div>
                            <details class="job-output">
                                <summary><?= hub_h(hub_i18n_text('工作輸出')) ?></summary>
                                <pre class="job-tail"></pre>
                            </details>
                        </div>

                        <details class="service-details">
                            <summary><?= hub_h(hub_i18n_text('技術詳細資料')) ?></summary>
                            <dl class="market-spec">
                                <dt><code>pack_id</code></dt>
                                <dd><code><?= hub_h((string)($service['pack_id'] ?? '')) ?></code></dd>
                                <dt><code>mode</code></dt>
                                <dd><code><?= hub_h((string)$service['mode']) ?></code></dd>
                                <dt><code>runtime_level</code></dt>
                                <dd><code><?= hub_h($runtimeLevel) ?></code></dd>
                                <dt><code>endpoint</code></dt>
                                <dd><code><?= hub_h($endpoint) ?></code></dd>
                                <dt><code>execution_type</code></dt>
                                <dd><code><?= hub_h((string)($service['execution_type'] ?? '')) ?></code></dd>
                                <dt><code>local_port</code></dt>
                                <dd><code><?= hub_h((string)($service['local_port'] ?? '')) ?></code> / <code><?= hub_h((string)$service['port_mode']) ?></code></dd>
                                <dt><code>environment</code></dt>
                                <dd><code><?= hub_h((string)($service['environment'] ?? '')) ?></code></dd>
                                <dt><code>config</code></dt>
                                <dd>
                                    <code><?= (int)($service['config_dirty'] ?? 0) === 1 ? 'config dirty' : 'config clean' ?></code>,
                                    <code><?= (int)($service['restart_required'] ?? 0) === 1 ? 'restart required' : 'applied' ?></code>
                                </dd>
                                <dt><?= hub_h(hub_i18n_text('API 入口')) ?></dt>
                                <dd><code id="service-api-url-<?= $serviceId ?>"><?= hub_h($apiUrl) ?></code></dd>
                                <dt><?= hub_h(hub_i18n_text('最近背景工作')) ?></dt>
                                <dd data-service-last-job>
                                    <?php if ($lastJob): ?>
                                        <?= hub_h(hub_i18n_text(hub_command_action_label((string)$lastJob['action']))) ?>
                                        <code><?= hub_h((string)$lastJob['action']) ?></code>
                                        <span data-service-last-job-status class="<?= hub_h(hub_command_status_class((string)$lastJob['status'])) ?>">
                                            <?= hub_h(hub_i18n_text(hub_command_status_label((string)$lastJob['status']))) ?>
                                        </span>
                                        <span><?= hub_h((string)($lastJob['updated_at'] ?? $lastJob['created_at'] ?? '')) ?></span>
                                    <?php else: ?>
                                        <?= hub_h(hub_i18n_text('尚無背景工作')) ?>
                                    <?php endif; ?>
                                </dd>
                            </dl>
                        </details>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <script id="market-i18n" type="application/json"><?= hub_json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/js/<?= $view === 'market' ? 'packs.js' : 'services.js' ?>"></script>
</div>
<?php hub_admin_footer(); ?>
