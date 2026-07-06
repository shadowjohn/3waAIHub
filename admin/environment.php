<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_login($db);
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

function hub_env_section_label(string $section): string
{
    return [
        'host' => '主機',
        'docker' => 'Docker',
        'gpu_cuda' => 'GPU / CUDA',
        'memory' => '記憶體',
        'disk' => '磁碟與資料目錄',
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
    ][$key] ?? $key;
}

function hub_env_status_label(string $status): string
{
    return ['ok' => '正常', 'error' => '錯誤'][$status] ?? $status;
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

function hub_env_fix_suggestions(array $data): array
{
    $host = $data['host'] ?? [];
    $docker = $data['docker'] ?? [];
    $disk = $data['disk'] ?? [];
    $workerUser = (string)($host['server_user'] ?? '');
    $displayUser = $workerUser !== '' ? $workerUser : 'COMMAND_WORKER_USER';
    $safeUser = escapeshellarg($displayUser);
    $suggestions = [];

    if (($docker['docker_installed'] ?? null) === false) {
        $suggestions[] = [
            'title' => '安裝 Docker',
            'body' => 'Docker 尚未安裝。這是 host bootstrap，需用 root 執行。',
            'commands' => "cd " . escapeshellarg(HUB_ROOT) . "\nsudo ./install.sh --bootstrap-host --with-docker",
        ];
    }

    if (($docker['daemon_reachable'] ?? null) === false) {
        $reason = (string)($docker['daemon_error'] ?? '');
        if (str_contains($reason, 'permission denied') || ($host['current_user_in_docker_group'] ?? null) === false) {
            $isWebUser = in_array($workerUser, ['www-data', 'apache', 'nginx'], true);
            $suggestions[] = [
                'title' => '讓 command worker 可操作 Docker',
                'body' => '不要把 www-data 加進 docker 群組。建議用本機帳號執行 command worker，並只把該帳號加入 docker 群組。docker 群組等同 root 權限，請只給可信任帳號。',
                'commands' => $isWebUser
                    ? "id 3waaihub-worker >/dev/null 2>&1 || sudo useradd -m -s /bin/bash -G docker 3waaihub-worker\nsudo -iu 3waaihub-worker docker info\nsudo -iu 3waaihub-worker php " . escapeshellarg(HUB_ROOT . '/scripts/command_worker.php') . " --limit=1"
                    : "sudo usermod -aG docker {$safeUser}\nsudo -iu {$safeUser} docker info\nsudo -iu {$safeUser} php " . escapeshellarg(HUB_ROOT . '/scripts/command_worker.php') . " --limit=1",
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
            'commands' => "cd " . escapeshellarg(HUB_ROOT) . "\nsudo WEB_GROUP=www-data ./scripts/fix_permissions.sh",
        ];
    }

    return $suggestions;
}

hub_admin_header('環境診斷', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<section class="panel">
    <h1>環境診斷</h1>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <button class="primary" type="submit">執行環境檢測</button>
    </form>
</section>
<?php if (!$snapshot): ?>
    <section class="panel muted">尚無環境快照，請先排程環境檢測並執行 command worker。</section>
<?php else: ?>
    <section class="panel">
        <h2>最新快照</h2>
        <p>狀態：<span class="<?= hub_status_class($snapshot['status']) ?>"><?= hub_h(hub_env_status_label($snapshot['status'])) ?></span></p>
        <p>建立時間：<?= hub_h($snapshot['created_at']) ?></p>
        <?php if ($snapshot['error_message']): ?><p class="bad"><?= hub_h($snapshot['error_message']) ?></p><?php endif; ?>
    </section>
    <?php $suggestions = hub_env_fix_suggestions($snapshot['data']); ?>
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
