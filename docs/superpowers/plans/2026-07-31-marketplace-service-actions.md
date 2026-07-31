# Marketplace Service Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Make installed-service cards show only valid start/stop actions and safely queue removal of a stopped, idle service.

**Architecture:** Keep the existing command-job flow: admin/marketplace.php validates and queues, while scripts/command_worker.php runs the shared removal function. A shared guard is authoritative for browser and worker, so a racing queued job or direct POST cannot delete a live service. Removal reuses hub_stop_service(), deletes only generated compose and environment files, then deletes the service row.

**Tech Stack:** PHP 8, SQLite, Docker Compose, jQuery, existing PHP assertion test runner.

---

## File Structure

- app/command_queue.php: register service_remove and query other queued/running jobs for one service.
- app/docker_runner.php: shared removal guard, generated-runtime validation, shutdown, file cleanup, and registration deletion.
- scripts/command_worker.php: dispatch service_remove.
- admin/marketplace.php: validate removal, render state-aware actions, and provide translated confirmation copy.
- assets/js/services.js: keep action state correct after AJAX polling and use native confirmation.
- admin/services.php: hide the retired service IP-whitelist entry.
- tests/test_phase_p1.php: worker-side fake-Docker safety tests.
- tests/test_admin_market.php: request and markup contracts.
- tests/test_phase_ui4.php: retired-page whitelist contract.

> **Current worktree guard:** app/docker_runner.php, scripts/command_worker.php, and tests/test_phase_p1.php already contain an uncommitted VoxCPM2 fix. Use `git add -p` for those three files and stage only the removal hunks described below; do not mix the prior fix into these commits.

### Task 1: Queue Contract And Guard

**Files:**
- Modify: app/command_queue.php:5-31,43-67,105-112
- Test: tests/test_phase_p1.php

- [ ] **Step 1: Write the failing queue contract test**

Add this test to tests/test_phase_p1.php:

~~~php
hub_test('PhaseP-1 service removal action and active-job guard are explicit', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');

    hub_test_assert(hub_is_valid_job_action('service_remove'), 'service_remove must be allowlisted');
    hub_test_assert(hub_service_has_active_command_job($db, (int)$service['id']) === false, 'fresh service must be idle');

    $jobId = hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], null, '127.0.0.1');
    hub_test_assert(hub_service_has_active_command_job($db, (int)$service['id']) === true, 'queued command must make service busy');
    hub_test_assert(
        hub_service_has_active_command_job($db, (int)$service['id'], $jobId) === false,
        'current removal job must be excludable from its own busy check'
    );
});
~~~

- [ ] **Step 2: Run the test and verify red**

Run:

~~~bash
php scripts/run_tests.php --suite=control-plane
~~~

Expected: the test fails because service_remove is unregistered and hub_service_has_active_command_job() does not exist.

- [ ] **Step 3: Implement the smallest queue addition**

Add service_remove after service_rebuild in hub_allowed_job_actions(), and add this action label:

~~~php
'service_remove' => '移除服務',
~~~

Add this function after hub_get_command_job():

~~~php
function hub_service_has_active_command_job(PDO $db, int $serviceId, ?int $excludingJobId = null): bool
{
    $sql = "SELECT 1 FROM command_jobs
            WHERE service_id = :service_id
              AND status IN ('queued', 'running')";
    $params = [':service_id' => $serviceId];
    if ($excludingJobId !== null) {
        $sql .= ' AND id != :excluding_job_id';
        $params[':excluding_job_id'] = $excludingJobId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn() !== false;
}
~~~

- [ ] **Step 4: Run green and commit**

Run:

~~~bash
php scripts/run_tests.php --suite=control-plane
git add app/command_queue.php
git add -p tests/test_phase_p1.php
git commit -m "feat: add service removal command guard"
~~~

Expected: the new guard test passes with the existing control-plane suite.

### Task 2: Worker-Side Shutdown And Removal

**Files:**
- Modify: app/docker_runner.php:591-666
- Modify: scripts/command_worker.php:70-88
- Test: tests/test_phase_p1.php

- [ ] **Step 1: Write failing removal tests**

Add these tests to tests/test_phase_p1.php:

~~~php
hub_test('PhaseP-1 service removal stops before deleting generated runtime files', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $serviceId = (int)$service['id'];
    $db->prepare("UPDATE services SET status = 'stopped', runtime_status = 'stopped' WHERE id = :id")
        ->execute([':id' => $serviceId]);
    $service = hub_get_service($db, $serviceId);
    $runtimeDir = hub_pack_runtime_dir($db, (string)$service['service_key']);
    mkdir($runtimeDir . '/artifacts', 0775, true);
    $artifact = $runtimeDir . '/artifacts/keep.txt';
    file_put_contents($artifact, 'keep');
    $compose = hub_path((string)$service['compose_file']);
    $env = dirname($compose) . '/.env';
    $jobId = hub_enqueue_command_job($db, 'service_remove', $serviceId, [], null, '127.0.0.1');

    $root = sys_get_temp_dir() . '/3waaihub_remove_' . bin2hex(random_bytes(4));
    $bin = $root . '/bin';
    $log = $root . '/docker.log';
    mkdir($bin, 0775, true);
    file_put_contents($bin . '/docker', "#!/bin/sh\nprintf '%s\\n' \" . '"$*"' . " >> \" . '"$MOCK_DOCKER_LOG"' . "\nexit 0\n");
    chmod($bin . '/docker', 0755);
    $path = getenv('PATH');
    try {
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        putenv('MOCK_DOCKER_LOG=' . $log);
        $result = hub_remove_service($db, $service, hub_get_command_job($db, $jobId));
        $commands = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        hub_test_assert($result['exit_code'] === 0, 'stopped idle service must remove successfully');
        hub_test_assert(count($commands) === 1 && str_contains($commands[0], ' down '), 'Compose down must run before deletion');
        hub_test_assert(hub_get_service($db, $serviceId) === null, 'service registration must be deleted after down');
        hub_test_assert(!is_file($compose) && !is_file($env), 'generated compose and env must be deleted');
        hub_test_assert(is_file($artifact), 'task artifacts must remain');
        hub_test_assert(hub_get_pack('hello') !== null, 'Pack definition must remain');
    } finally {
        putenv($path === false ? 'PATH' : 'PATH=' . $path);
        putenv('MOCK_DOCKER_LOG');
        @unlink($bin . '/docker');
        @unlink($log);
        @rmdir($bin);
        @rmdir($root);
    }
});

hub_test('PhaseP-1 service removal rejects running and busy services', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    $serviceId = (int)$service['id'];
    $removeJobId = hub_enqueue_command_job($db, 'service_remove', $serviceId, [], null, '127.0.0.1');

    $db->prepare("UPDATE services SET status = 'running', runtime_status = 'running' WHERE id = :id")
        ->execute([':id' => $serviceId]);
    hub_test_assert(
        hub_remove_service($db, hub_get_service($db, $serviceId), hub_get_command_job($db, $removeJobId))['exit_code'] !== 0,
        'running service removal must be rejected'
    );

    $db->prepare("UPDATE services SET status = 'stopped', runtime_status = 'stopped' WHERE id = :id")
        ->execute([':id' => $serviceId]);
    hub_enqueue_command_job($db, 'service_start', $serviceId, [], null, '127.0.0.1');
    hub_test_assert(
        hub_remove_service($db, hub_get_service($db, $serviceId), hub_get_command_job($db, $removeJobId))['exit_code'] !== 0,
        'busy service removal must be rejected'
    );
    hub_test_assert(hub_get_service($db, $serviceId) !== null, 'rejected removal must keep the service');
});
~~~

- [ ] **Step 2: Run the test and verify red**

Run:

~~~bash
php scripts/run_tests.php --suite=control-plane
~~~

Expected: the removal tests fail because hub_remove_service() does not exist.

- [ ] **Step 3: Implement one shared guard and the removal routine**

Insert before hub_stop_service() in app/docker_runner.php:

~~~php
function hub_service_removal_block_reason(PDO $db, array $service, ?int $excludingJobId = null): ?string
{
    $status = (string)($service['runtime_status'] ?? $service['status'] ?? 'stopped');
    if ($status !== 'stopped') {
        return 'service_not_stopped';
    }
    if (hub_service_has_active_command_job($db, (int)$service['id'], $excludingJobId)) {
        return 'service_job_active';
    }

    return null;
}

function hub_service_generated_runtime_files(PDO $db, array $service): ?array
{
    $serviceKey = (string)($service['service_key'] ?? '');
    if ($serviceKey === '' || (string)($service['compose_file'] ?? '') !== hub_pack_compose_file($db, $serviceKey)) {
        return null;
    }

    $runtimeDir = hub_pack_runtime_dir($db, $serviceKey);
    $composePath = hub_path((string)$service['compose_file']);
    if (dirname($composePath) !== $runtimeDir) {
        return null;
    }

    return [$composePath, $runtimeDir . '/.env'];
}

function hub_remove_service(PDO $db, array $service, array $job): array
{
    $reason = hub_service_removal_block_reason($db, $service, (int)$job['id']);
    if ($reason !== null) {
        return ['exit_code' => 2, 'stdout' => '', 'stderr' => $reason, 'error_code' => $reason];
    }
    $runtimeFiles = hub_service_generated_runtime_files($db, $service);
    if ($runtimeFiles === null) {
        return ['exit_code' => 2, 'stdout' => '', 'stderr' => 'service_runtime_files_unmanaged', 'error_code' => 'service_runtime_files_unmanaged'];
    }

    hub_job_progress($db, $job, 'docker_down', 10, 'Stopping service before removal.');
    $stopped = hub_stop_service($db, $service, $job);
    if ((int)$stopped['exit_code'] !== 0) {
        return $stopped;
    }

    hub_job_progress($db, $job, 'remove_runtime_files', 85, 'Removing generated runtime files.');
    foreach ($runtimeFiles as $path) {
        if (is_file($path) && !unlink($path)) {
            return ['exit_code' => 1, 'stdout' => (string)$stopped['stdout'], 'stderr' => 'Cannot remove generated runtime file: ' . basename($path)];
        }
    }

    $db->prepare('DELETE FROM services WHERE id = :id')->execute([':id' => (int)$service['id']]);
    hub_job_progress($db, $job, 'remove_registration', 95, 'Removed service registration.');

    return ['exit_code' => 0, 'stdout' => trim((string)$stopped['stdout'] . "\nRemoved service registration."), 'stderr' => (string)$stopped['stderr']];
}
~~~

Change only hub_stop_service() so current callers remain valid but worker removal streams shutdown progress:

~~~php
function hub_stop_service(PDO $db, array $service, ?array $job = null): array
{
    // Preserve the existing internal-task and runtime-support branches.
    $result = hub_run_service_compose_command($db, $job, $service, ['down', '--timeout', '5'], 10, 'docker_down', 10, 80);
    // Preserve the existing log and status update branches.
}
~~~

In scripts/command_worker.php, add this match arm beside service_stop:

~~~php
'service_remove' => hub_remove_service($db, $service, $job),
~~~

- [ ] **Step 4: Run green and commit**

Run:

~~~bash
php scripts/run_tests.php --suite=control-plane
git add -p app/docker_runner.php scripts/command_worker.php tests/test_phase_p1.php
git commit -m "feat: remove stopped services through worker"
~~~

Expected: fake Docker records Compose down, only generated compose/env are removed, artifacts and Pack remain, and running/busy services stay registered.

### Task 3: Marketplace Validation And Rendered Actions

**Files:**
- Modify: admin/marketplace.php:104-146,176-220,528-678
- Test: tests/test_admin_market.php

- [ ] **Step 1: Write failing request and HTML tests**

Add this test to tests/test_admin_market.php:

~~~php
hub_test('canonical service removal queues only stopped idle services', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    $request = ['csrf_token' => 'test', 'service_id' => (string)$service['id'], 'action' => 'remove'];
    $queued = json_decode(hub_test_admin_market_request(['view' => 'services'], $request, true)['stdout'], true);
    hub_test_assert(($queued['ok'] ?? false) === true, 'stopped idle service removal must queue');
    hub_test_assert((hub_get_command_job($db, (int)$queued['job']['id'])['action'] ?? '') === 'service_remove', 'remove must queue service_remove');

    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    $db->prepare("UPDATE services SET status = 'running', runtime_status = 'running' WHERE id = :id")->execute([':id' => (int)$service['id']]);
    $running = json_decode(hub_test_admin_market_request(['view' => 'services'], $request + ['service_id' => (string)$service['id']], true)['stdout'], true);
    hub_test_assert(($running['ok'] ?? true) === false, 'running service removal must not queue');

    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], null, '127.0.0.1');
    $busy = json_decode(hub_test_admin_market_request(['view' => 'services'], $request + ['service_id' => (string)$service['id']], true)['stdout'], true);
    hub_test_assert(($busy['ok'] ?? true) === false, 'busy service removal must not queue');
});

hub_test('canonical installed services render state-aware controls without the legacy whitelist', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    $serviceId = (int)$service['id'];

    $stopped = hub_test_admin_market_request(['view' => 'services'])['stdout'];
    hub_test_assert(str_contains($stopped, 'value="start"'), 'stopped service must show start');
    hub_test_assert(!str_contains($stopped, 'value="stop"'), 'stopped service must hide stop');
    hub_test_assert(str_contains($stopped, 'value="remove"'), 'stopped idle service must show remove');
    hub_test_assert(!str_contains($stopped, 'service_whitelist.php'), 'canonical service card must hide legacy whitelist');

    $db->prepare("UPDATE services SET status = 'running', runtime_status = 'running' WHERE id = :id")->execute([':id' => $serviceId]);
    $running = hub_test_admin_market_request(['view' => 'services'])['stdout'];
    hub_test_assert(!str_contains($running, 'value="start"'), 'running service must hide start');
    hub_test_assert(str_contains($running, 'value="stop"'), 'running service must show stop');
    hub_test_assert(!str_contains($running, 'value="remove"'), 'running service must hide remove');
});
~~~

- [ ] **Step 2: Run the test and verify red**

Run:

~~~bash
php scripts/run_tests.php --suite=control-plane
~~~

Expected: remove is an unknown action, the card has both start/stop, and the legacy whitelist URL remains.

- [ ] **Step 3: Implement queueing, validation, and markup**

Add this action map entry:

~~~php
'remove' => 'service_remove',
~~~

Before the existing enqueue call, validate removal using the worker guard:

~~~php
if ($action === 'remove') {
    $reason = hub_service_removal_block_reason($db, $service);
    if ($reason === 'service_not_stopped') {
        $error = __('請先停止服務後再移除。');
    } elseif ($reason === 'service_job_active') {
        $error = __('服務仍有背景工作，完成後再移除。');
    }
}
if ($error !== '') {
    if ($isAjax) {
        hub_marketplace_json(409, ['ok' => false, 'error' => $error]);
    }
} else {
    $queueAction = $actionMap[$action];
    // Keep the existing enqueue and AJAX payload code in this branch.
}
~~~

Add the following dictionary entries:

~~~php
'action_service_remove' => __('移除服務'),
'confirm_remove_service' => __('確定移除此服務嗎？這會停止服務並刪除服務設定；Pack、映像、模型、快取與既有任務產物會保留。'),
~~~

Inside the service loop, compute:

~~~php
$serviceBusy = $activeJob !== null;
$showStart = !$serviceBusy && !in_array($actualState, ['running', 'starting'], true);
$showStop = !$serviceBusy && !in_array($actualState, ['stopped', 'error', 'failed'], true);
$showRemove = !$serviceBusy && $actualState === 'stopped';
~~~

Add data-service-busy to the service article, replace the operation buttons with state-gated start/stop/remove buttons using data-service-action and data-service-remove, and add disabled to restart/build/rebuild/refresh when serviceBusy. Remove the complete 相容工具 dt/dd pair from the technical details.

Use this exact removal button:

~~~php
<?php if ($showRemove): ?>
    <button class="danger" data-service-action="remove" data-service-remove name="action" value="remove" type="submit"><?= hub_h(__('移除')) ?></button>
<?php endif; ?>
~~~

- [ ] **Step 4: Run green and commit**

Run:

~~~bash
php scripts/run_tests.php --suite=control-plane
git add admin/marketplace.php tests/test_admin_market.php
git commit -m "feat: add marketplace service removal action"
~~~

Expected: only stopped idle services enter the queue; server-rendered controls match status; no marketplace legacy whitelist URL remains.

### Task 4: Polling State, Native Confirmation, And Retired Page Cleanup

**Files:**
- Modify: assets/js/services.js:21-31,187-252,399-443
- Modify: admin/services.php:297-301
- Modify: tests/test_admin_market.php
- Modify: tests/test_phase_ui4.php

- [ ] **Step 1: Write failing source contracts**

Add this test to tests/test_admin_market.php:

~~~php
hub_test('service polling preserves action visibility and native removal confirmation', function (): void {
    $script = (string)file_get_contents(HUB_ROOT . '/assets/js/services.js');
    foreach (['service_remove', 'data-service-action="start"', 'data-service-remove', 'data-service-busy', 'window.confirm', 'syncServiceActionState'] as $needle) {
        hub_test_assert(str_contains($script, $needle), 'services polling contract missing ' . $needle);
    }
});
~~~

In tests/test_phase_ui4.php, replace the positive legacy-link assertions with:

~~~php
hub_test_assert(!str_contains($page, 'service_whitelist.php'), 'legacy whitelist link must be hidden from retired services page');
hub_test_assert(!str_contains($page, '僅保留相容用途'), 'legacy whitelist explanation must leave retired services page');
~~~

Also remove 舊版 IP 白名單 from that test's rendered-page needles.

- [ ] **Step 2: Run the test and verify red**

Run:

~~~bash
php scripts/run_tests.php --suite=control-plane
~~~

Expected: the JS source lacks removal handling and the retired page still links to service_whitelist.php.

- [ ] **Step 3: Implement the client state rules**

Add to jobActionLabel():

~~~javascript
service_remove: t('action_service_remove', '移除服務'),
~~~

Add this function before syncServiceState():

~~~javascript
function syncServiceActionState($row, actualStatus, busy) {
    if (!$row.length) {
        return;
    }
    var isRunning = actualStatus === 'running' || actualStatus === 'starting';
    var isStopped = actualStatus === 'stopped' || actualStatus === 'error' || actualStatus === 'failed';
    $row.attr('data-service-busy', busy ? '1' : '0');
    $row.find('[data-service-action="start"]').toggle(!isRunning);
    $row.find('[data-service-action="stop"]').toggle(!isStopped);
    $row.find('[data-service-remove]').toggle(actualStatus === 'stopped' && !busy);
    $row.find('.service-action-form button[name="action"]').prop('disabled', busy);
}
~~~

Call it at the end of syncServiceState():

~~~javascript
syncServiceActionState(
    $row,
    actualStatus || $row.attr('data-service-actual-status') || '',
    job.status === 'queued' || job.status === 'running'
);
~~~

Before the generic action click handler, add native confirmation:

~~~javascript
$(document).on('click', '.service-action-form [data-service-remove]', function (event) {
    if (window.confirm(t('confirm_remove_service', '確定移除此服務嗎？這會停止服務並刪除服務設定；Pack、映像、模型、快取與既有任務產物會保留。'))) {
        return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
});
~~~

In submitServiceAction() replace unconditional button re-enable in always() with:

~~~javascript
var $row = $form.closest('[data-service-row-id]');
$form.find('button').prop('disabled', $row.attr('data-service-busy') === '1');
~~~

Initialize current PHP-rendered cards before window.setInterval():

~~~javascript
$('[data-service-row-id]').each(function () {
    var $row = $(this);
    syncServiceActionState($row, $row.attr('data-service-actual-status') || '', $row.attr('data-service-busy') === '1');
});
~~~

- [ ] **Step 4: Hide the retired entry**

Delete only the details block at admin/services.php:297-301. Keep the retired page and its direct URL available.

- [ ] **Step 5: Verify and commit**

Run:

~~~bash
php -l app/command_queue.php
php -l app/docker_runner.php
php -l scripts/command_worker.php
php -l admin/marketplace.php
php -l admin/services.php
php scripts/run_tests.php --suite=control-plane
git diff --check
git add assets/js/services.js admin/services.php tests/test_admin_market.php tests/test_phase_ui4.php
git commit -m "fix: align marketplace service actions"
~~~

Expected: lint passes, control-plane passes, no whitespace errors remain, and the new behavior is completely covered.
