<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_system_admin($db);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hub_check_csrf();
    $jobId = hub_enqueue_command_job(
        $db,
        'env_probe',
        null,
        ['reason' => 'admin_environment'],
        (int)$user['id'],
        $_SERVER['REMOTE_ADDR'] ?? null
    );
    $message = '已排入環境診斷工作 #' . $jobId . '，請等待 command worker 執行。';
}

$snapshot = hub_latest_env_snapshot($db);
$hostMetricSnapshot = hub_latest_host_metric_snapshot($db);
$hostMetricData = is_array($hostMetricSnapshot['data'] ?? null) ? $hostMetricSnapshot['data'] : [];
$liveWorkerStatus = hub_collect_command_worker_status();
if ($snapshot) {
    $snapshot['data']['command_worker'] = $liveWorkerStatus;
}
$releaseReport = hub_release_node_report($db);
$remoteRelease = hub_release_read_remote_cache();
$stationReleaseReports = [];
if (hub_table_exists($db, 'cluster_stations')) {
    $stations = hub_cluster_list_stations($db);
    $selfStationKey = hub_get_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY');
    foreach ($stations as $station) {
        if ($selfStationKey !== '' && hash_equals($selfStationKey, (string)$station['station_key'])) {
            $station['is_self'] = true;
            $station['self_router_enabled'] = hub_cluster_router_enabled($db);
            $station['self_node_enabled'] = hub_cluster_node_enabled($db);
            $station['self_children_count'] = count($stations);
        }
        $stationReleaseReports[] = hub_release_station_report($station, $releaseReport);
    }
}
usort($stationReleaseReports, static function (array $left, array $right): int {
    $order = ['aggregate' => 0, 'parent' => 1, 'child' => 2, 'unknown' => 3];
    $comparison = ($order[(string)($left['node_type'] ?? 'unknown')] ?? 3)
        <=> ($order[(string)($right['node_type'] ?? 'unknown')] ?? 3);

    return $comparison !== 0
        ? $comparison
        : strcmp((string)($left['display_name'] ?? ''), (string)($right['display_name'] ?? ''));
});

function hub_env_section_label(string $section): string
{
    return [
        'host' => '主機',
        'docker' => 'Docker',
        'gpu_cuda' => 'GPU / CUDA',
        'storage' => 'Storage',
        'memory' => '記憶體',
        'disk' => '磁碟與資料目錄',
        'command_worker' => '背景 Worker',
        'web_protection' => 'Web 檔案防護',
    ][$section] ?? $section;
}

function hub_env_key_label(string $key): string
{
    return [
        'hostname' => '主機名稱',
        'os_kernel' => '作業系統 / 核心',
        'php_version' => 'PHP 版本',
        'app_path' => 'App 路徑',
        'app_user' => 'App 檔案使用者',
        'server_user' => '執行使用者',
        'sqlite_path' => 'SQLite 路徑',
        'sqlite_writable' => 'SQLite 可寫',
        'docker_group_warning' => 'Docker 群組警告',
        'current_user_in_docker_group' => '目前使用者在 docker 群組',
        'docker_installed' => 'Docker 已安裝',
        'docker_compose_installed' => 'Docker Compose 已安裝',
        'docker_version' => 'Docker 版本',
        'compose_version' => 'Docker Compose 版本',
        'daemon_reachable' => 'Docker daemon 可連線',
        'docker_root_dir' => 'Docker Root Dir',
        'docker_root_free_bytes' => 'Docker Root Dir 可用空間',
        'docker_root_warning' => 'Docker Root Dir 警告',
        'docker_root_status' => 'Docker Root Dir 狀態',
        'docker_error' => 'Docker 錯誤',
        'compose_error' => 'Compose 錯誤',
        'daemon_error' => 'Docker daemon 錯誤',
        'disk_usage' => 'Docker 磁碟用量',
        'nvidia_smi_available' => 'nvidia-smi 可用',
        'nvidia_smi_exit_code' => 'nvidia-smi exit code',
        'nvidia_smi_error' => 'nvidia-smi 錯誤',
        'name' => 'GPU 名稱',
        'driver_version' => 'Driver 版本',
        'cuda_version' => 'CUDA 版本',
        'cuda_version_reason' => 'CUDA 版本原因',
        'vram_total_mb' => 'VRAM 總量',
        'vram_used_mb' => 'VRAM 已用',
        'vram_free_mb' => 'VRAM 可用',
        'utilization_percent' => 'GPU 使用率',
        'temperature_c' => 'GPU 溫度',
        'total_bytes' => '總容量',
        'free_bytes' => '可用容量',
        'available_bytes' => '可分配容量',
        'used_bytes' => '已用容量',
        'project_total_bytes' => '專案磁碟總量',
        'project_free_bytes' => '專案磁碟可用',
        'project_used_bytes' => '專案磁碟已用',
        'data_writable' => 'data/ 可寫',
        'logs_writable' => 'logs/ 可寫',
        'packs_readable' => 'packs/ 可讀',
        'AIHUB_MODELS_DIR' => 'Models Dir',
        'AIHUB_CACHE_DIR' => 'Cache Dir',
        'AIHUB_UPLOADS_DIR' => 'Uploads Dir',
        'AIHUB_RESULTS_DIR' => 'Results Dir',
        'AIHUB_LOGS_DIR' => 'Logs Dir',
        'AIHUB_DOCKER_PORT_START' => 'Docker Port 起始',
        'AIHUB_DOCKER_PORT_END' => 'Docker Port 結束',
        'AIHUB_MODELS_DIR_exists' => 'Models Dir 存在',
        'AIHUB_MODELS_DIR_readable' => 'Models Dir 可讀',
        'AIHUB_MODELS_DIR_writable' => 'Models Dir 可寫',
        'AIHUB_MODELS_DIR_total_bytes' => 'Models Dir 總空間',
        'AIHUB_MODELS_DIR_free_bytes' => 'Models Dir 可用空間',
        'AIHUB_CACHE_DIR_exists' => 'Cache Dir 存在',
        'AIHUB_CACHE_DIR_readable' => 'Cache Dir 可讀',
        'AIHUB_CACHE_DIR_writable' => 'Cache Dir 可寫',
        'AIHUB_CACHE_DIR_total_bytes' => 'Cache Dir 總空間',
        'AIHUB_CACHE_DIR_free_bytes' => 'Cache Dir 可用空間',
        'AIHUB_UPLOADS_DIR_exists' => 'Uploads Dir 存在',
        'AIHUB_UPLOADS_DIR_readable' => 'Uploads Dir 可讀',
        'AIHUB_UPLOADS_DIR_writable' => 'Uploads Dir 可寫',
        'AIHUB_UPLOADS_DIR_total_bytes' => 'Uploads Dir 總空間',
        'AIHUB_UPLOADS_DIR_free_bytes' => 'Uploads Dir 可用空間',
        'AIHUB_RESULTS_DIR_exists' => 'Results Dir 存在',
        'AIHUB_RESULTS_DIR_readable' => 'Results Dir 可讀',
        'AIHUB_RESULTS_DIR_writable' => 'Results Dir 可寫',
        'AIHUB_RESULTS_DIR_total_bytes' => 'Results Dir 總空間',
        'AIHUB_RESULTS_DIR_free_bytes' => 'Results Dir 可用空間',
        'AIHUB_LOGS_DIR_exists' => 'Logs Dir 存在',
        'AIHUB_LOGS_DIR_readable' => 'Logs Dir 可讀',
        'AIHUB_LOGS_DIR_writable' => 'Logs Dir 可寫',
        'AIHUB_LOGS_DIR_total_bytes' => 'Logs Dir 總空間',
        'AIHUB_LOGS_DIR_free_bytes' => 'Logs Dir 可用空間',
        'cron_installed' => 'command worker cron 已掛載',
        'cron_file' => 'cron 設定檔',
        'cron_user' => 'cron 執行使用者',
        'cron_line' => 'cron 指令',
        'loop_script_exists' => 'crontab/1min.sh 存在',
        'loop_script_executable' => 'crontab/1min.sh 可執行',
        'flock_available' => 'flock 可用',
        'log_path' => 'worker log',
        'log_exists' => 'worker log 已產生',
        'last_log_at' => '最後 log 時間',
        'cluster_refresh_configured' => 'Cluster 節點重新整理已排程',
        'cluster_refresh_log_path' => 'Cluster 節點重新整理 log',
        'last_cluster_refresh_log_at' => '最後 Cluster 節點重新整理 log 時間',
        'install_command' => '掛載指令',
        'manual_command' => 'Windows 手動執行指令',
        'apache_active' => 'Apache 執行中',
        'nginx_active' => 'Nginx 執行中',
        'apache_rules_present' => 'Apache .htaccess 靜態規則完整',
        'iis_rules_present' => 'IIS 規則完整',
    ][$key] ?? $key;
}

function hub_env_status_label(string $status): string
{
    return ['ok' => '正常', 'error' => '錯誤', 'not_applicable' => '不適用'][$status] ?? $status;
}

function hub_env_format_bytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, $unit === 0 ? 0 : 2) . ' ' . $units[$unit] . ' (' . number_format((float)$bytes, 0) . ' bytes)';
}

function hub_env_format_mb(int|float|string $mb): string
{
    if (!is_numeric($mb)) {
        return hub_h((string)$mb);
    }

    $mbValue = (float)$mb;
    if ($mbValue >= 1024) {
        return number_format($mbValue / 1024, 2) . ' GB (' . number_format($mbValue, 0) . ' MB)';
    }

    return number_format($mbValue, 0) . ' MB';
}

function hub_env_false_reason(string $key, array $values): string
{
    $candidates = [$key . '_reason', $key . '_error'];
    if (str_ends_with($key, '_available')) {
        $candidates[] = substr($key, 0, -10) . '_error';
    }
    if ($key === 'docker_installed') {
        $candidates[] = 'docker_error';
    }
    if ($key === 'docker_compose_installed') {
        $candidates[] = 'compose_error';
    }
    if ($key === 'daemon_reachable') {
        $candidates[] = 'daemon_error';
    }
    $knownReasons = [
        'cron_installed' => '尚未掛載 command worker cron。請以 root 執行安裝指令。',
        'current_user_in_docker_group' => '不一定要修。若 Docker 操作由 root cron/command worker 執行，這是安全狀態；若要讓目前帳號手動跑 Docker，再參考下方修正建議。',
        'loop_script_exists' => '找不到 crontab/1min.sh。',
        'loop_script_executable' => 'crontab/1min.sh 尚未設為可執行。',
        'flock_available' => '找不到 flock，請安裝 util-linux。',
        'log_exists' => 'worker 尚未執行或尚未寫入 log。',
        'cluster_refresh_configured' => 'Cluster 節點重新整理未由既有的一分鐘 cron 排程。',
    ];
    if (isset($knownReasons[$key])) {
        return $knownReasons[$key];
    }

    foreach ($candidates as $candidate) {
        if (!empty($values[$candidate]) && is_scalar($values[$candidate])) {
            return (string)$values[$candidate];
        }
    }
    if (str_ends_with($key, '_writable')) {
        return '目前執行使用者沒有寫入權限。';
    }
    if (str_ends_with($key, '_readable')) {
        return '目前執行使用者沒有讀取權限。';
    }
    if (str_ends_with($key, '_exists')) {
        return '目錄不存在，請用 CLI 建立並修正權限。';
    }

    return '';
}

function hub_env_should_skip(string $key, mixed $value, array $values): bool
{
    if (str_ends_with($key, '_error')) {
        return true;
    }
    if (str_ends_with($key, '_reason')) {
        return array_key_exists(substr($key, 0, -7), $values);
    }

    return $value === null || $value === '';
}

function hub_env_render_value(string $key, mixed $value, array $values): string
{
    if (is_array($value) && (($value['status'] ?? '') === 'not_applicable')) {
        return '<span class="muted">N/A（不適用）</span>';
    }
    if ($value === true) {
        return '<strong class="ok">是</strong>';
    }
    if ($value === false) {
        $reason = hub_env_false_reason($key, $values);
        return '<strong class="bad">否</strong>' . ($reason !== '' ? '<div class="reason">原因：' . hub_h($reason) . '</div>' : '');
    }
    if ($value === null) {
        return '<span class="muted">無資料</span>';
    }
    if ((str_ends_with($key, '_bytes') || in_array($key, ['total_bytes', 'free_bytes', 'available_bytes', 'used_bytes'], true)) && is_numeric($value)) {
        return hub_h(hub_env_format_bytes((float)$value));
    }
    if (str_ends_with($key, '_mb')) {
        return hub_h(hub_env_format_mb($value));
    }
    if ($key === 'utilization_percent' && is_numeric($value)) {
        return hub_h((string)$value) . '%';
    }
    if ($key === 'temperature_c' && is_numeric($value)) {
        return hub_h((string)$value) . ' °C';
    }
    if (is_array($value)) {
        return '<pre class="inline-pre">' . hub_h(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
    }
    if (is_string($value) && str_contains($value, "\n")) {
        return '<pre class="inline-pre">' . hub_h($value) . '</pre>';
    }

    return hub_h((string)$value);
}

function hub_env_ps_literal(string $value): string
{
    return hub_powershell_single_quoted_literal($value);
}

function hub_env_windows_worker_command(): string
{
    $worker = HUB_ROOT . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'command_worker.php';

    return "Set-Location -LiteralPath " . hub_env_ps_literal(HUB_ROOT) . "\nphp " . hub_env_ps_literal($worker) . ' --limit=5';
}

function hub_env_windows_core_command(): string
{
    return "Set-Location -LiteralPath " . hub_env_ps_literal(HUB_ROOT) . "\n.\\install.ps1 -Check\n.\\install.ps1";
}

function hub_env_windows_wsl_check_command(): string
{
    return "Set-Location -LiteralPath " . hub_env_ps_literal(HUB_ROOT)
        . "\n.\\install.ps1 -Check"
        . "\nwsl.exe --status"
        . "\nwsl.exe --list --verbose";
}

function hub_env_normalize_fix_suggestions(array $suggestions): array
{
    if (hub_platform_id() !== 'windows') {
        return $suggestions;
    }

    $normalized = [];
    foreach ($suggestions as $suggestion) {
        $title = (string)($suggestion['title'] ?? '');
        if (in_array($title, ['修正 Docker socket 權限', '安裝 Docker Compose plugin'], true)) {
            $suggestion = [
                'title' => '檢查 WSL Runtime（Preview）',
                'body' => '3waAIHub Core（Control Plane）不修 Linux Docker socket 權限；Linux Pack 請先確認 WSL runtime target readiness。',
                'commands' => hub_env_windows_wsl_check_command(),
            ];
        } elseif ($title === '修正 Docker GPU runtime') {
            $suggestion = [
                'title' => '檢查 WSL Runtime（Preview）GPU',
                'body' => 'Windows GPU Pack 需透過 WSL Runtime（Preview）或 remote Linux agent 執行；先跑 WslRuntime read-only check。',
                'commands' => hub_env_windows_wsl_check_command(),
            ];
        }
        $key = (string)$suggestion['title'] . "\n" . (string)$suggestion['commands'];
        $normalized[$key] = $suggestion;
    }

    return array_values($normalized);
}

function hub_env_fix_suggestions(array $data): array
{
    $host = $data['host'] ?? [];
    $docker = $data['docker'] ?? [];
    $disk = $data['disk'] ?? [];
    $worker = $data['command_worker'] ?? [];
    $protection = is_array($data['web_protection'] ?? null) ? $data['web_protection'] : [];
    $workerUserValue = $host['server_user'] ?? '';
    $workerUser = is_scalar($workerUserValue) ? (string)$workerUserValue : '';
    $displayUser = $workerUser !== '' ? $workerUser : 'COMMAND_WORKER_USER';
    $safeUser = escapeshellarg($displayUser);
    $suggestions = [];
    $isWindows = hub_platform_id() === 'windows';

    if (($worker['cron_installed'] ?? null) === false) {
        $suggestions[] = [
            'title' => $isWindows ? '執行 Windows command worker' : '掛載 command worker cron',
            'body' => $isWindows
                ? '3waAIHub Core（Control Plane）不掛 Linux cron；有 queued command_jobs 時先用 PowerShell 手動消化。'
                : '後台啟停服務會先排入 command_jobs，再由 crontab/1min.sh 消化。掛載 cron 需 root；一般 install 非 root 時只會提示。',
            'commands' => $isWindows
                ? hub_env_windows_worker_command()
                : "cd " . escapeshellarg(HUB_ROOT) . "\nsudo ./scripts/install_command_worker_cron.sh\n# 指定可信任本機帳號：sudo WORKER_USER=john ./scripts/install_command_worker_cron.sh",
        ];
    }

    if (!$isWindows && ($worker['flock_available'] ?? true) === false) {
        $suggestions[] = [
            'title' => '安裝 flock',
            'body' => 'crontab/1min.sh 使用 flock 防止同一分鐘內重複執行 worker。',
            'commands' => "sudo apt-get update\nsudo apt-get install -y util-linux",
        ];
    }

    if (($docker['docker_installed'] ?? null) === false) {
        $suggestions[] = [
            'title' => $isWindows ? '檢查 WSL Runtime（Preview）' : '安裝 Docker',
            'body' => $isWindows
                ? '3waAIHub Core（Control Plane）不需要本機 Linux Docker；Linux Pack 請改檢查 WSL Runtime（Preview）target。'
                : 'Docker 尚未安裝。這是 host bootstrap，需用 root 執行。',
            'commands' => $isWindows
                ? hub_env_windows_wsl_check_command()
                : "cd " . escapeshellarg(HUB_ROOT) . "\nsudo ./install.sh --bootstrap-host --with-docker",
        ];
    }

    $dockerGroupMissing = ($host['current_user_in_docker_group'] ?? null) === false;
    $isWebUser = in_array($workerUser, ['www-data', 'apache', 'nginx'], true);
    if (!$isWindows && $dockerGroupMissing) {
        $suggestions[] = [
            'title' => 'Docker 操作帳號建議',
            'body' => '目前執行使用者不在 docker 群組。不要把 www-data 加進 docker 群組；Docker group 等同 root 權限。建議讓 command worker 用 root cron，或建立可信任本機帳號專門跑 worker。',
            'commands' => $isWebUser
                ? "cd " . escapeshellarg(HUB_ROOT) . "\nsudo ./scripts/install_command_worker_cron.sh\n# 或建立專用帳號：\nid 3waaihub-worker >/dev/null 2>&1 || sudo useradd -m -s /bin/bash -G docker 3waaihub-worker\nsudo -iu 3waaihub-worker docker info\nsudo env WORKER_USER=3waaihub-worker ./scripts/install_command_worker_cron.sh"
                : "# 若你要讓 {$safeUser} 直接操作 Docker：\nsudo usermod -aG docker {$safeUser}\n# 重新登入後驗證：\nsudo -iu {$safeUser} docker info\n# 讓 command worker 使用此帳號：\ncd " . escapeshellarg(HUB_ROOT) . "\nsudo env WORKER_USER={$safeUser} ./scripts/install_command_worker_cron.sh",
        ];
    }

    if (($docker['daemon_reachable'] ?? null) === false) {
        $reason = (string)($docker['daemon_error'] ?? '');
        if ($isWindows) {
            $suggestions[] = [
                'title' => '檢查 WSL Runtime（Preview）',
                'body' => '3waAIHub Core（Control Plane）不直接修 Linux Docker daemon；服務與 Pack runtime 請走 WSL Runtime（Preview）或 remote Linux agent。',
                'commands' => hub_env_windows_wsl_check_command(),
            ];
        } elseif (str_contains($reason, 'permission denied')) {
            $suggestions[] = [
                'title' => '讓 command worker 可操作 Docker',
                'body' => '不要把 www-data 加進 docker 群組。建議用本機帳號執行 command worker，並只把該帳號加入 docker 群組。docker 群組等同 root 權限，請只給可信任帳號。',
                'commands' => $isWebUser
                    ? "id 3waaihub-worker >/dev/null 2>&1 || sudo useradd -m -s /bin/bash -G docker 3waaihub-worker\nsudo -iu 3waaihub-worker docker info\ncd " . escapeshellarg(HUB_ROOT) . "\nsudo env WORKER_USER=3waaihub-worker ./scripts/install_command_worker_cron.sh"
                    : "sudo usermod -aG docker {$safeUser}\nsudo -iu {$safeUser} docker info\ncd " . escapeshellarg(HUB_ROOT) . "\nsudo env WORKER_USER={$safeUser} ./scripts/install_command_worker_cron.sh",
            ];
        } else {
            $suggestions[] = [
                'title' => '啟動 Docker daemon',
                'body' => 'Docker CLI 存在，但 daemon 目前不可連線。',
                'commands' => "sudo systemctl enable --now docker\nsudo systemctl status docker --no-pager\ndocker info",
            ];
        }
    }

    if (($disk['data_writable'] ?? true) === false || ($disk['logs_writable'] ?? true) === false) {
        $suggestions[] = [
            'title' => '修正 runtime 權限',
            'body' => 'PHP 執行使用者無法寫入 data/logs，會造成 SQLite 或 log 儲存失敗。',
            'commands' => $isWindows
                ? hub_env_windows_core_command()
                : "cd " . escapeshellarg(HUB_ROOT) . "\nsudo WEB_GROUP=www-data ./scripts/fix_permissions.sh",
        ];
    }

    if (!$isWindows && ($protection['nginx_active'] ?? false) === true) {
        $suggestions[] = [
            'title' => '設定 Nginx 檔案防護',
            'body' => 'Nginx 不讀 .htaccess。請把下列規則放進此站的 server 區塊，驗證 nginx -t 後再 reload。',
            'commands' => hub_web_protection_nginx_snippet() . "\n\nsudo nginx -t\nsudo systemctl reload nginx",
        ];
    } elseif (!$isWindows && ($protection['apache_active'] ?? false) === true && ($protection['apache_rules_present'] ?? false) !== true) {
        $suggestions[] = [
            'title' => '修正 Apache 檔案防護',
            'body' => 'Apache 已啟用，但 .htaccess 缺少必要規則。這裡只做 .htaccess 靜態完整性檢查；實際生效以 live HTTP 存取檢查為準。請確認 AllowOverride Options FileInfo AuthConfig Limit 後重新套用專案的 .htaccess。',
            'commands' => "sudo apache2ctl -t\nsudo systemctl reload apache2",
        ];
    } elseif ($isWindows && ($protection['iis_rules_present'] ?? false) !== true) {
        $suggestions[] = [
            'title' => '修正 IIS 檔案防護',
            'body' => 'web.config 缺少 Hub 的 runtime data 或 directory browse 防護規則。請從主線還原 web.config 後重新套用 IIS site。',
            'commands' => ".\\scripts\\windows\\configure-iis-fastcgi.ps1",
        ];
    }

    return $suggestions;
}

hub_admin_header('系統環境', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<section class="panel">
    <h1>系統環境</h1>
    <?php if (hub_platform_id() === 'windows'): ?><p class="muted">3waAIHub Core（Control Plane）／WSL Runtime（Preview）readiness 分開顯示；Linux-only 項目為 N/A。</p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <button class="primary" type="submit">執行環境檢測</button>
    </form>
</section>
<section class="panel">
    <h2><?= hub_h(hub_i18n_text('版本與節點相容性')) ?></h2>
    <p class="muted"><?= hub_h(hub_i18n_text('此頁只顯示版本與相容性，不會執行更新或部署。')) ?></p>
    <div class="table-wrap">
        <table>
            <tr>
                <th><?= hub_h(hub_i18n_text('目前版本')) ?></th>
                <td><code><?= hub_h((string)$releaseReport['git']['display_version']) ?></code> / <?= hub_h(HUB_RELEASE_LABEL) ?></td>
            </tr>
            <tr>
                <th><?= hub_h(hub_i18n_text('工作樹狀態')) ?></th>
                <td class="<?= $releaseReport['git']['dirty'] === null ? 'muted' : ($releaseReport['git']['dirty'] ? 'bad' : 'ok') ?>">
                    <?= hub_h(hub_i18n_text($releaseReport['git']['dirty'] === null ? '未知' : ($releaseReport['git']['dirty'] ? '有未提交變更' : '乾淨'))) ?>
                </td>
            </tr>
            <tr>
                <th><?= hub_h(hub_i18n_text('最後檢查時間')) ?></th>
                <td><?= hub_h((string)($remoteRelease['checked_at'] ?: hub_i18n_text('未知'))) ?><?php if ($remoteRelease['error'] !== ''): ?> / <code><?= hub_h((string)$remoteRelease['error']) ?></code><?php endif; ?></td>
            </tr>
        </table>
    </div>
    <h3><?= hub_h(hub_i18n_text('Cluster 節點版本')) ?></h3>
    <?php if ($stationReleaseReports === []): ?>
        <p class="muted"><?= hub_h(hub_i18n_text('目前沒有已配對的子節點。')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th><?= hub_h(hub_i18n_text('項次')) ?></th>
                    <th><?= hub_h(hub_i18n_text('站台')) ?></th>
                    <th><?= hub_h(hub_i18n_text('版本')) ?></th>
                    <th><?= hub_h(hub_i18n_text('節點類型')) ?></th>
                    <th><?= hub_h(hub_i18n_text('健康')) ?></th>
                    <th><?= hub_h(hub_i18n_text('Pack數')) ?></th>
                    <th><?= hub_h(hub_i18n_text('狀況')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($stationReleaseReports as $index => $stationRelease): ?>
                    <tr>
                        <td><?= number_format($index + 1) ?></td>
                        <td><?= hub_h((string)$stationRelease['display_name']) ?></td>
                        <td><code><?= hub_h((string)($stationRelease['display_version'] ?: hub_i18n_text('未知'))) ?></code></td>
                        <td>
                            <?= hub_h(match ((string)$stationRelease['node_type']) {
                                'aggregate' => hub_i18n_text('主節點+子節點'),
                                'parent' => hub_i18n_text('主節點'),
                                'child' => hub_i18n_text('子節點'),
                                default => hub_i18n_text('未知'),
                            }) ?>
                        </td>
                        <td>
                            <?php $health = (string)$stationRelease['health']; ?>
                            <span class="<?= $health === 'ok' ? 'ok' : ($health === 'degraded' ? 'bad' : 'muted') ?>"><?= hub_h(hub_release_status_label($health)) ?></span>
                        </td>
                        <td>
                            <?php if ($stationRelease['pack_count'] === null): ?>
                                <span class="muted"><?= hub_h(hub_i18n_text('未知')) ?></span>
                            <?php else: ?>
                                <?= number_format((int)$stationRelease['pack_count']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($stationRelease['update_needed'] === null): ?>
                                <span class="muted"><?= hub_h(hub_i18n_text('未知')) ?></span>
                            <?php else: ?>
                                <span class="<?= $stationRelease['update_needed'] ? 'bad' : 'ok' ?>"><?= hub_h(hub_i18n_text($stationRelease['update_needed'] ? '需要更新' : '已對齊')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<section class="panel" id="webProtectionLive">
    <h2>即時 Web 檔案防護</h2>
    <p class="muted" id="webProtectionLiveStatus">正在檢查同源保護規則...</p>
    <ul id="webProtectionLiveList" hidden></ul>
</section>
<script>
(() => {
    const root = new URL('../', window.location.href);
    const targets = ['data/cluster.key', 'docs/cluster-router.md', 'scripts/init_db.php'];
    const status = document.getElementById('webProtectionLiveStatus');
    const list = document.getElementById('webProtectionLiveList');
    Promise.all(targets.map(async (path) => {
        try {
            const response = await fetch(new URL(path, root), {
                method: 'HEAD',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'manual',
            });
            const safe = [403, 404].includes(response.status);
            return {path, safe, result: safe ? 'PASS（安全）' : `FAIL (${response.status})`};
        } catch {
            return {path, safe: false, result: 'FAIL (network)'};
        }
    })).then((rows) => {
        list.replaceChildren(...rows.map((row) => {
            const item = document.createElement('li');
            item.className = row.safe ? 'ok' : 'bad';
            item.textContent = `${row.path}: ${row.result}`;
            return item;
        }));
        list.hidden = false;
        status.textContent = rows.every((row) => row.safe) ? '同源保護規則檢查完成。' : '部分同源保護規則檢查失敗。';
    });
})();
</script>
<?php if (!$snapshot): ?>
    <section class="panel muted">尚無環境快照，請先排程環境檢測並執行 command worker。</section>
    <section class="panel">
        <h2><?= hub_h(hub_env_section_label('command_worker')) ?></h2>
        <table>
            <?php foreach ($liveWorkerStatus as $key => $value): ?>
                <?php if (hub_env_should_skip((string)$key, $value, $liveWorkerStatus)) { continue; } ?>
                <tr>
                    <th><?= hub_h(hub_env_key_label((string)$key)) ?></th>
                    <td><?= hub_env_render_value((string)$key, $value, $liveWorkerStatus) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>
    <?php $suggestions = hub_env_normalize_fix_suggestions(array_merge(hub_env_fix_suggestions(['command_worker' => $liveWorkerStatus]), hub_host_metric_fix_suggestions($hostMetricData))); ?>
    <?php if ($suggestions): ?>
        <section class="panel">
            <h2>修正建議</h2>
            <?php foreach ($suggestions as $suggestion): ?>
                <h3><?= hub_h($suggestion['title']) ?></h3>
                <p><?= hub_h($suggestion['body']) ?></p>
                <pre class="inline-pre"><?= hub_h($suggestion['commands']) ?></pre>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php else: ?>
    <section class="panel">
        <h2>最新快照</h2>
        <p>狀態：<span class="<?= hub_status_class($snapshot['status']) ?>"><?= hub_h(hub_env_status_label($snapshot['status'])) ?></span></p>
        <p>建立時間：<?= hub_h($snapshot['created_at']) ?></p>
        <?php if ($snapshot['error_message']): ?><p class="bad"><?= hub_h($snapshot['error_message']) ?></p><?php endif; ?>
    </section>
    <?php $snapshotServerUser = $snapshot['data']['host']['server_user'] ?? ''; ?>
    <?php $suggestions = hub_env_normalize_fix_suggestions(array_merge(hub_env_fix_suggestions($snapshot['data']), hub_host_metric_fix_suggestions($hostMetricData, is_scalar($snapshotServerUser) ? (string)$snapshotServerUser : ''))); ?>
    <?php if ($suggestions): ?>
        <section class="panel">
            <h2>修正建議</h2>
            <?php foreach ($suggestions as $suggestion): ?>
                <h3><?= hub_h($suggestion['title']) ?></h3>
                <p><?= hub_h($suggestion['body']) ?></p>
                <pre class="inline-pre"><?= hub_h($suggestion['commands']) ?></pre>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <?php foreach ($snapshot['data'] as $section => $values): ?>
        <section class="panel">
            <h2><?= hub_h(hub_env_section_label((string)$section)) ?></h2>
            <table>
                <?php foreach ($values as $key => $value): ?>
                    <?php if (hub_env_should_skip((string)$key, $value, $values)) { continue; } ?>
                    <tr>
                        <th><?= hub_h(hub_env_key_label((string)$key)) ?></th>
                        <td><?= hub_env_render_value((string)$key, $value, $values) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php hub_admin_footer(); ?>
