<?php
declare(strict_types=1);

function hub_admin_record_tabs(): array
{
    return [
        'runs' => '執行歷程',
        'api' => 'API 記錄',
        'jobs' => '背景工作',
        'service' => '服務記錄',
        'system' => '系統記錄',
    ];
}

function hub_admin_record_tab(mixed $value): string
{
    $value = hub_admin_record_input_string($value);

    return array_key_exists($value, hub_admin_record_tabs()) ? $value : 'runs';
}

function hub_admin_record_input_string(mixed $value): string
{
    return is_scalar($value) ? trim((string)$value) : '';
}

function hub_admin_record_positive_int(mixed $value): int
{
    $value = hub_admin_record_input_string($value);

    return ctype_digit($value) ? max(0, (int)$value) : 0;
}

function hub_admin_record_token(mixed $value): string
{
    $value = hub_admin_record_input_string($value);

    return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $value) === 1 ? $value : '';
}

function hub_admin_record_short_text(string $value, int $limit): string
{
    $limit = max(4, min(6000, $limit));

    return strlen($value) <= $limit ? $value : substr($value, 0, $limit - 3) . '...';
}

function hub_admin_record_runtime_filters(array $source): array
{
    $filters = [];
    foreach (['pack_id', 'task', 'state'] as $key) {
        $value = hub_admin_record_input_string($source[$key] ?? '');
        $filters[$key] = preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $value) === 1 ? $value : '';
    }
    $filters['q'] = substr(hub_admin_record_input_string($source['q'] ?? ''), 0, 200);

    return $filters;
}

function hub_admin_record_runtime_runs(PDO $db, array $filters, int $limit = 100): array
{
    $filters = hub_admin_record_runtime_filters($filters);
    $where = [];
    $params = [];
    foreach (['pack_id', 'task', 'state'] as $key) {
        if ($filters[$key] !== '') {
            $where[] = $key . ' = :' . $key;
            $params[':' . $key] = $filters[$key];
        }
    }
    if ($filters['q'] !== '') {
        $where[] = 'run_id LIKE :q';
        $params[':q'] = '%' . $filters['q'] . '%';
    }

    $sql = 'SELECT * FROM runtime_runs'
        . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY started_at DESC, id DESC LIMIT :limit';
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function hub_admin_record_api_filters(array $source): array
{
    $encodedIp = hub_admin_record_input_string($source['client_ip_b64'] ?? '');
    $clientIp = hub_decode_ip_get_filter($encodedIp, false);

    return [
        'time_from' => hub_admin_record_time_filter($source['time_from'] ?? ''),
        'time_to' => hub_admin_record_time_filter($source['time_to'] ?? ''),
        'client_ip_b64' => $clientIp === null ? '' : $encodedIp,
        'mode' => hub_admin_record_token($source['mode'] ?? ''),
        'service_id' => hub_admin_record_positive_int($source['service_id'] ?? 0),
        'member_id' => hub_admin_record_positive_int($source['member_id'] ?? 0),
        'token_id' => hub_admin_record_positive_int($source['token_id'] ?? 0),
        'ok' => in_array(hub_admin_record_input_string($source['ok'] ?? ''), ['0', '1'], true) ? hub_admin_record_input_string($source['ok']) : '',
        'status_code' => ctype_digit(hub_admin_record_input_string($source['status_code'] ?? '')) ? hub_admin_record_input_string($source['status_code']) : '',
        'error_code' => hub_admin_record_token($source['error_code'] ?? ''),
        'method' => hub_admin_record_token($source['method'] ?? ''),
        'request_id' => hub_admin_record_token($source['request_id'] ?? ''),
        'keyword' => substr(hub_admin_record_input_string($source['keyword'] ?? ''), 0, 200),
    ];
}

/**
 * 舊 API access-log 入口只導向記錄中心的 API tab；所有查詢值都先走既有 filter。
 */
function hub_admin_record_api_redirect_query(array $source): array
{
    $clientIp = hub_admin_record_input_string($source['client_ip'] ?? '');
    if ($clientIp !== '' && filter_var($clientIp, FILTER_VALIDATE_IP)) {
        $source['client_ip_b64'] = aihub_b64url_encode($clientIp);
    }
    unset($source['client_ip']);

    $filters = hub_admin_record_api_filters($source);
    $query = ['tab' => 'api'];
    foreach ($filters as $key => $value) {
        if ($value !== '' && $value !== 0) {
            $query[$key] = $value;
        }
    }

    return $query;
}

function hub_admin_record_log_explorer_url(array $query): string
{
    $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $url = 'log_explorer.php' . ($encoded !== '' ? '?' . $encoded : '');
    if (strlen($url) > 4096 || preg_match('/\Alog_explorer\.php(?:\?[A-Za-z0-9._~%=&-]*)?\z/D', $url) !== 1) {
        throw new InvalidArgumentException('Log explorer redirect is invalid.');
    }

    return $url;
}

function hub_admin_record_time_filter(mixed $value): string
{
    $value = substr(hub_admin_record_input_string($value), 0, 32);

    return preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value) === 1 ? $value : '';
}

function hub_admin_record_job_statuses(): array
{
    return ['queued', 'running', 'success', 'failed', 'cancelled', 'timeout'];
}

function hub_admin_record_job_filters(array $source): array
{
    $status = hub_admin_record_input_string($source['status'] ?? '');
    $action = hub_admin_record_input_string($source['action'] ?? '');

    return [
        'status' => in_array($status, hub_admin_record_job_statuses(), true) ? $status : '',
        'action' => hub_is_valid_job_action($action) ? $action : '',
        'service_id' => hub_admin_record_positive_int($source['service_id'] ?? 0),
        'keyword' => substr(hub_admin_record_input_string($source['keyword'] ?? ''), 0, 200),
        'time_from' => hub_admin_record_time_filter($source['time_from'] ?? ''),
        'time_to' => hub_admin_record_time_filter($source['time_to'] ?? ''),
    ];
}

function hub_admin_record_command_jobs(PDO $db, array $filters, int $limit = 200): array
{
    $filters = hub_admin_record_job_filters($filters);
    $where = [];
    $params = [];
    if ($filters['status'] !== '') {
        $where[] = 'cj.status = :status';
        $params[':status'] = $filters['status'];
    }
    if ($filters['action'] !== '') {
        $where[] = 'cj.action = :action';
        $params[':action'] = $filters['action'];
    }
    if ($filters['service_id'] > 0) {
        $where[] = 'cj.service_id = :service_id';
        $params[':service_id'] = $filters['service_id'];
    }
    if ($filters['time_from'] !== '') {
        $where[] = 'cj.created_at >= :time_from';
        $params[':time_from'] = $filters['time_from'];
    }
    if ($filters['time_to'] !== '') {
        $where[] = 'cj.created_at <= :time_to';
        $params[':time_to'] = $filters['time_to'];
    }
    if ($filters['keyword'] !== '') {
        $where[] = '(cj.action LIKE :keyword OR cj.stage LIKE :keyword OR cj.current_message LIKE :keyword OR cj.error_message LIKE :keyword OR s.name LIKE :keyword OR s.service_key LIKE :keyword)';
        $params[':keyword'] = '%' . $filters['keyword'] . '%';
    }

    $sql = 'SELECT cj.*, s.name AS service_name, s.service_key, u.username AS requested_by_username
            FROM command_jobs cj
            LEFT JOIN services s ON s.id = cj.service_id
            LEFT JOIN users u ON u.id = cj.requested_by'
        . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY cj.id DESC LIMIT :limit';
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function hub_admin_record_tail_file(string $path, int $limit = 6000): string
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return '';
    }
    $base = realpath(HUB_DATA_DIR);
    $real = realpath($path);
    if ($base === false || $real === false || ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR))) {
        return '';
    }

    $limit = max(1, min(6000, $limit));
    $size = filesize($real);
    if ($size === false) {
        return '';
    }
    $handle = fopen($real, 'rb');
    if ($handle === false) {
        return '';
    }
    if ($size > $limit) {
        fseek($handle, -$limit, SEEK_END);
    }
    $content = stream_get_contents($handle);
    fclose($handle);

    return $content === false ? '' : $content;
}

function hub_admin_record_service_logs(PDO $db, int $serviceId = 0, int $limit = 200): array
{
    $sql = 'SELECT l.*, s.name AS service_name, s.service_key
            FROM service_logs l
            JOIN services s ON s.id = l.service_id';
    if ($serviceId > 0) {
        $sql .= ' WHERE l.service_id = :service_id';
    }
    $sql .= ' ORDER BY l.id DESC LIMIT :limit';
    $stmt = $db->prepare($sql);
    if ($serviceId > 0) {
        $stmt->bindValue(':service_id', $serviceId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function hub_admin_record_system_logs(PDO $db, int $limit = 200): array
{
    $stmt = $db->prepare('SELECT * FROM audit_logs ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
