<?php
declare(strict_types=1);

function hub_playground_facebook_targets(string $value): array
{
    $lines = preg_split('/\R/u', trim($value)) ?: [];
    $targets = [];
    foreach ($lines as $line) {
        $url = trim($line);
        if ($url === '') {
            continue;
        }
        $targets[] = ['url' => $url];
    }
    if ($targets === []) {
        $targets[] = ['url' => 'https:' . '//www.facebook.com/wra.gov.tw'];
    }

    return $targets;
}

function hub_playground_facebook_request_payload(array $post): array
{
    $payload = [
        'targets' => hub_playground_facebook_targets((string)($post['facebook_targets'] ?? '')),
        'limit_per_target' => (int)($post['facebook_limit_per_target'] ?? 10),
    ];
    $profileId = trim((string)($post['facebook_profile_id'] ?? ''));
    if ($profileId !== '') {
        $payload = ['profile_id' => $profileId] + $payload;
    }

    return $payload;
}

function hub_playground_facebook_result_from_gateway(array $response, float $started): array
{
    $headers = implode("\r\n", array_map('strval', (array)($response['headers'] ?? [])));
    $contentType = 'application/json';
    foreach ((array)($response['headers'] ?? []) as $header) {
        if (stripos((string)$header, 'Content-Type:') === 0) {
            $contentType = trim(substr((string)$header, strlen('Content-Type:')));
            break;
        }
    }
    $result = hub_playground_parse_response(
        (int)($response['status'] ?? 500),
        $headers,
        $contentType,
        (string)($response['body'] ?? ''),
        (int)round((microtime(true) - $started) * 1000)
    );
    if (empty($result['ok'])) {
        $result['message'] = hub_playground_error_message((int)($response['status'] ?? 500), '', (string)($result['error'] ?? ''));
    }

    return hub_playground_facebook_public_links($result);
}

function hub_playground_facebook_public_links(array $result): array
{
    $payload = json_decode((string)($result['body'] ?? ''), true);
    if (!is_array($payload)) {
        return $result;
    }
    $taskId = filter_var($payload['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($taskId !== false) {
        foreach (['status_url' => 'task_status', 'result_url' => 'task_result', 'log_url' => 'task_log', 'cancel_url' => 'task_cancel'] as $key => $mode) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = hub_playground_api_url($mode) . '&task_id=' . $taskId;
            }
        }
        if (array_key_exists('dataset_items_url', $payload)) {
            $payload['dataset_items_url'] = hub_playground_api_url('facebook_dataset_items') . '&task_id=' . $taskId;
        }
    }
    $artifactId = filter_var($payload['artifact_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($artifactId === false && is_string($payload['artifact_url'] ?? null)) {
        parse_str((string)parse_url($payload['artifact_url'], PHP_URL_QUERY), $artifactQuery);
        $artifactId = filter_var($artifactQuery['artifact_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }
    if ($artifactId !== false && array_key_exists('artifact_url', $payload)) {
        $payload['artifact_url'] = hub_playground_api_url('artifact') . '&artifact_id=' . $artifactId;
    }
    if (is_string($payload['login_url'] ?? null) && str_starts_with($payload['login_url'], 'facebook_profile_login.php#session=')) {
        $payload['login_url'] = hub_playground_facebook_page_url($payload['login_url']);
    }
    $body = hub_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result['body'] = $body;
    $result['pretty_body'] = hub_playground_pretty_json($body);

    return $result;
}

function hub_playground_facebook_page_url(string $relative): string
{
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return ($secure ? 'https:' : 'http:') . '//' . $host . hub_playground_base_path() . '/' . ltrim($relative, '/');
}

function hub_playground_facebook_dispatch(PDO $db, string $mode, string $token, string $method, array $payload = [], array $query = []): array
{
    $started = microtime(true);
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $request = [
        'method' => $method,
        'request_uri' => hub_playground_base_path() . '/api.php?mode=' . rawurlencode($mode),
        'bearer_token' => $token,
        'client_ip' => hub_get_client_ip(),
        'https' => $secure,
        'query' => $query,
    ];
    if ($method === 'POST') {
        $request['raw_body'] = hub_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $request['content_type'] = 'application/json';
    }

    return hub_playground_facebook_result_from_gateway(hub_gateway_dispatch($db, $mode, null, $request), $started);
}

function hub_playground_facebook_profile_options_result(PDO $db, string $token): array
{
    $started = microtime(true);
    $auth = hub_authenticate_api_token($db, hub_get_client_ip(), trim($token), 'facebook_crawl');
    if (empty($auth['ok'])) {
        return hub_playground_facebook_result_from_gateway((array)$auth['response'], $started);
    }
    $profiles = hub_facebook_profiles_for_member($db, (int)($auth['context']['member_id'] ?? 0));
    $response = hub_gateway_json(200, ['ok' => true, 'profiles' => $profiles]);
    $result = hub_playground_facebook_result_from_gateway($response, $started);
    $result['profiles'] = $profiles;

    return $result;
}

function hub_playground_facebook_action(PDO $db, string $action, string $token, array $post): array
{
    if ($action === 'facebook_profile_list') {
        return hub_playground_facebook_profile_options_result($db, $token);
    }
    if ($action === 'facebook_profile_start') {
        $method = (string)($post['facebook_login_method'] ?? 'browser');
        $payload = ['display_name' => trim((string)($post['facebook_display_name'] ?? 'Facebook Profile')), 'method' => $method];
        if ($method === 'password') {
            $payload['username'] = trim((string)($post['facebook_username'] ?? ''));
            $payload['password'] = (string)($post['facebook_password'] ?? '');
        }
        return hub_playground_facebook_dispatch($db, 'facebook_profile_start', $token, 'POST', $payload);
    }

    $profileId = trim((string)($post['facebook_profile_manage_id'] ?? ''));
    if ($action === 'facebook_profile_status') {
        return hub_playground_facebook_dispatch($db, 'facebook_profile_status', $token, 'GET', [], ['profile_id' => $profileId]);
    }
    if ($action === 'facebook_profile_reauth') {
        return hub_playground_facebook_dispatch($db, 'facebook_profile_reauth', $token, 'POST', ['profile_id' => $profileId, 'method' => 'browser']);
    }
    if ($action === 'facebook_profile_delete') {
        return hub_playground_facebook_dispatch($db, 'facebook_profile_delete', $token, 'POST', ['profile_id' => $profileId]);
    }
    if ($action === 'facebook_run_last') {
        return hub_playground_facebook_dispatch($db, 'facebook_run_last', $token, 'GET');
    }
    if ($action === 'facebook_dataset_preview') {
        $query = ['offset' => 0, 'limit' => 10];
        $taskId = trim((string)($post['facebook_task_id'] ?? ''));
        if ($taskId !== '') {
            $query['task_id'] = $taskId;
        }
        return hub_playground_facebook_dispatch($db, 'facebook_dataset_items', $token, 'GET', [], $query);
    }

    return hub_playground_guard_result(['error' => 'invalid_action', 'message' => __('無效的操作。')]);
}

function hub_playground_facebook_request_fields_html(array $profiles, array $post): string
{
    $selected = trim((string)($post['facebook_profile_id'] ?? ''));
    $targets = (string)($post['facebook_targets'] ?? ('https:' . '//www.facebook.com/wra.gov.tw'));
    $limit = max(10, min(30, (int)($post['facebook_limit_per_target'] ?? 10)));
    ob_start();
    ?>
    <label><?= hub_h(__('Facebook 登入 Profile')) ?> profile_id</label>
    <select name="facebook_profile_id">
        <option value=""><?= hub_h(__('公開頁面，不使用登入 Profile')) ?></option>
        <?php foreach ($profiles as $profile): ?>
            <?php $profileId = (string)($profile['profile_id'] ?? ''); ?>
            <option value="<?= hub_h($profileId) ?>" <?= $selected === $profileId ? 'selected' : '' ?>><?= hub_h((string)($profile['display_name'] ?? $profileId)) ?> / <?= hub_h((string)($profile['state'] ?? '')) ?></option>
        <?php endforeach; ?>
    </select>
    <label><?= hub_h(__('目標粉絲專頁／社團')) ?></label>
    <textarea name="facebook_targets" rows="6" required><?= hub_h($targets) ?></textarea>
    <p class="muted"><?= hub_h(__('每行一個 Facebook URL；一次最多 30 個目標。')) ?></p>
    <label><?= hub_h(__('每個目標近期文章數')) ?> limit_per_target</label>
    <input name="facebook_limit_per_target" type="number" min="10" max="30" value="<?= $limit ?>" required>
    <?php
    return (string)ob_get_clean();
}

function hub_playground_facebook_management_html(array $profiles): string
{
    ob_start();
    ?>
    <hr>
    <h3><?= hub_h(__('Facebook 登入 Profile')) ?></h3>
    <p class="muted"><?= hub_h(__('登入狀態只保存在本機節點；2FA 或 CAPTCHA 請在短效登入頁手動完成。')) ?></p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="action" value="facebook_profile_start">
        <input type="hidden" name="mode" value="facebook_crawl">
        <label>Bearer Token</label>
        <input name="bearer_token" type="password" placeholder="&lt;TOKEN&gt;" autocomplete="off" required>
        <label><?= hub_h(__('Profile 名稱')) ?></label>
        <input name="facebook_display_name" maxlength="120" required>
        <label><?= hub_h(__('登入方式')) ?></label>
        <select name="facebook_login_method">
            <option value="browser"><?= hub_h(__('瀏覽器手動登入')) ?></option>
            <option value="password"><?= hub_h(__('帳號密碼輔助登入')) ?></option>
        </select>
        <label><?= hub_h(__('Facebook 帳號（僅密碼輔助登入）')) ?></label>
        <input name="facebook_username" autocomplete="off">
        <label><?= hub_h(__('Facebook 密碼（不保存）')) ?></label>
        <input name="facebook_password" type="password" autocomplete="new-password">
        <div class="hub-actions"><button class="primary" type="submit"><?= hub_h(__('開啟短效登入')) ?></button></div>
    </form>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="mode" value="facebook_crawl">
        <label>Bearer Token</label>
        <input name="bearer_token" type="password" placeholder="&lt;TOKEN&gt;" autocomplete="off" required>
        <label><?= hub_h(__('已管理 Profile')) ?></label>
        <select name="facebook_profile_manage_id" required>
            <option value=""><?= hub_h(__('請先載入 Profile')) ?></option>
            <?php foreach ($profiles as $profile): ?>
                <option value="<?= hub_h((string)$profile['profile_id']) ?>"><?= hub_h((string)$profile['display_name']) ?> / <?= hub_h((string)$profile['state']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="hub-actions">
            <button type="submit" name="action" value="facebook_profile_status"><?= hub_h(__('查看狀態')) ?></button>
            <button type="submit" name="action" value="facebook_profile_reauth"><?= hub_h(__('重新登入')) ?></button>
            <button class="danger" type="submit" name="action" value="facebook_profile_delete"><?= hub_h(__('刪除 Profile')) ?></button>
        </div>
    </form>
    <hr>
    <h3><?= hub_h(__('Dataset 預覽')) ?></h3>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="mode" value="facebook_crawl">
        <label>Bearer Token</label>
        <input name="bearer_token" type="password" placeholder="&lt;TOKEN&gt;" autocomplete="off" required>
        <label>task_id <?= hub_h(__('（留空取最新可用 Dataset）')) ?></label>
        <input name="facebook_task_id" type="number" min="1">
        <div class="hub-actions">
            <button type="submit" name="action" value="facebook_run_last"><?= hub_h(__('最後一次 Run')) ?></button>
            <button type="submit" name="action" value="facebook_dataset_preview"><?= hub_h(__('Dataset 預覽')) ?></button>
        </div>
    </form>
    <?php
    return (string)ob_get_clean();
}

function hub_playground_facebook_result_html(?array $result): string
{
    $payload = is_array($result) ? json_decode((string)($result['body'] ?? ''), true) : null;
    $payload = is_array($payload) ? $payload : [];
    ob_start();
    ?>
    <div data-facebook-task-links>
        <h3><?= hub_h(__('任務連結')) ?></h3>
        <div class="hub-actions">
            <?php foreach (['login_url', 'status_url', 'result_url', 'log_url', 'dataset_items_url', 'artifact_url'] as $key): ?>
                <?php if (is_string($payload[$key] ?? null) && $payload[$key] !== ''): ?>
                    <a class="button" href="<?= hub_h($payload[$key]) ?>" target="_blank" rel="noopener noreferrer"><?= hub_h($key) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <div data-facebook-dataset-preview>
        <h3><?= hub_h(__('Dataset 預覽')) ?></h3>
        <?php if (is_array($payload['items'] ?? null)): ?>
            <pre><?= hub_h((string)json_encode($payload['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
        <?php else: ?>
            <p class="muted"><?= hub_h(__('完成背景任務後，可用 task_id 預覽前 10 筆資料。')) ?></p>
        <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}
