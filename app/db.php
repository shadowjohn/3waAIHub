<?php
declare(strict_types=1);

function hub_sqlite_schema_identifier(string $identifier): string
{
    if (
        preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $identifier) !== 1
        || str_starts_with(strtolower($identifier), 'sqlite_')
    ) {
        throw new InvalidArgumentException('Invalid SQLite schema identifier.');
    }

    return '"' . $identifier . '"';
}

function hub_sqlite_begin_immediate(PDO $db, ?array &$stats = null): void
{
    // ponytail: SQLite has one writer; move sustained write contention to Postgres.
    $stats = ['lock_wait_ms' => 0.0, 'retry_count' => 0, 'lock_exhausted' => false];
    $startedNs = hrtime(true);
    for ($attempt = 0; $attempt < 7; $attempt++) {
        try {
            $db->exec('BEGIN IMMEDIATE');
            $stats['lock_wait_ms'] = round((hrtime(true) - $startedNs) / 1_000_000, 3);
            return;
        } catch (PDOException $e) {
            $locked = str_contains(strtolower($e->getMessage()), 'database is locked');
            if (!$locked || $attempt === 6) {
                $stats['lock_exhausted'] = $locked && $attempt === 6;
                $stats['lock_wait_ms'] = round((hrtime(true) - $startedNs) / 1_000_000, 3);
                throw $e;
            }
            usleep(5000 * (1 << $attempt));
            $stats['retry_count']++;
        }
    }
}

const HUB_DB_MIGRATION_VERSION = '2026-08-09.2';
const HUB_DB_MIGRATION_VERSION_KEY = 'db_migration_version';
const HUB_DB_MIGRATION_SCHEMA_KEY = 'db_migration_schema_version';

function hub_sqlite_insert_safe(PDO $db, string $table, array $fields): int
{
    if ($table !== 'settings' || array_keys($fields) !== ['key', 'value', 'updated_at']) {
        throw new InvalidArgumentException('Unsupported SQLite insert contract.');
    }
    foreach ($fields as $value) {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Invalid SQLite insert value.');
        }
    }

    $statement = $db->prepare(
        'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)'
    );
    $statement->execute([
        ':key' => $fields['key'],
        ':value' => $fields['value'],
        ':updated_at' => $fields['updated_at'],
    ]);

    return (int)$db->lastInsertId();
}

function hub_sqlite_update_safe(PDO $db, string $table, array $fields, array $where): int
{
    if ($table !== 'settings' || array_keys($fields) !== ['value'] || array_keys($where) !== ['key']) {
        throw new InvalidArgumentException('Unsupported SQLite update contract.');
    }
    if ((!is_scalar($fields['value']) && $fields['value'] !== null)
        || (!is_scalar($where['key']) && $where['key'] !== null)) {
        throw new InvalidArgumentException('Invalid SQLite update value.');
    }

    $statement = $db->prepare('UPDATE settings SET value = :value WHERE key = :key');
    $statement->execute([':value' => $fields['value'], ':key' => $where['key']]);

    return $statement->rowCount();
}

function hub_sqlite_select_setting_safe(PDO $db, string $key): ?array
{
    if ($key === '') {
        throw new InvalidArgumentException('SQLite settings key is required.');
    }

    $statement = $db->prepare('SELECT key, value, updated_at FROM settings WHERE key = :key');
    $statement->execute([':key' => $key]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function hub_sqlite_rebuild_index_contract(string $table): array
{
    return match ($table) {
        'service_logs' => [
            'idx_legacy_service_logs_action' => [
                'unique' => false,
                'columns' => ['action'],
                'restore' => true,
            ],
        ],
        'playground_tts_artifacts' => [
            'idx_playground_tts_artifacts_owner' => [
                'unique' => false,
                'columns' => ['owner_member_id', 'service_id'],
                'restore' => false,
            ],
        ],
        'cluster_routes' => [
            'idx_cluster_routes_legacy_remote_task' => [
                'unique' => false,
                'columns' => ['remote_task_id'],
                'restore' => true,
            ],
            'idx_cluster_routes_station_state' => [
                'unique' => false,
                'columns' => ['station_id', 'state', 'updated_at'],
                'restore' => false,
            ],
            'idx_cluster_routes_member_token' => [
                'unique' => false,
                'columns' => ['member_id', 'token_id', 'created_at'],
                'restore' => false,
            ],
        ],
        default => throw new InvalidArgumentException('Unsupported SQLite rebuild table.'),
    };
}

function hub_sqlite_capture_rebuild_indexes(PDO $db, string $table, array $allowedColumns): array
{
    $allowed = array_fill_keys($allowedColumns, true);
    if (count($allowed) !== count($allowedColumns)) {
        throw new InvalidArgumentException('Invalid SQLite rebuild columns.');
    }
    $contracts = hub_sqlite_rebuild_index_contract($table);
    $indexList = $db->prepare(
        'SELECT name, [unique] AS is_unique, origin, partial FROM pragma_index_list(:table_name)'
    );
    $indexList->execute([':table_name' => $table]);
    $indexes = [];
    foreach ($indexList->fetchAll() as $index) {
        if (($index['origin'] ?? '') !== 'c') {
            continue;
        }
        $name = (string)($index['name'] ?? '');
        $contract = $contracts[$name] ?? null;
        if (!is_array($contract) || (int)($index['partial'] ?? 0) !== 0
            || (int)($index['is_unique'] ?? 0) !== (!empty($contract['unique']) ? 1 : 0)) {
            throw new RuntimeException('Unsupported SQLite index during table rebuild.');
        }

        $indexInfo = $db->prepare('SELECT seqno, cid, name FROM pragma_index_info(:index_name) ORDER BY seqno');
        $indexInfo->execute([':index_name' => $name]);
        $columns = [];
        foreach ($indexInfo->fetchAll() as $column) {
            $columnName = (string)($column['name'] ?? '');
            if (!isset($allowed[$columnName])) {
                throw new RuntimeException('Invalid SQLite index column during table rebuild.');
            }
            $columns[] = $columnName;
        }
        if ($columns === []) {
            throw new RuntimeException('SQLite index has no rebuildable columns.');
        }
        if ($columns !== ($contract['columns'] ?? [])) {
            throw new RuntimeException('Unsupported SQLite index during table rebuild.');
        }
        if (!empty($contract['restore'])) {
            $indexes[] = $name;
        }
    }

    return $indexes;
}

function hub_sqlite_restore_rebuild_indexes(PDO $db, string $table, array $indexes): void
{
    foreach ($indexes as $name) {
        if (!is_string($name)) {
            throw new InvalidArgumentException('Invalid SQLite rebuild index.');
        }
        if ($table === 'cluster_routes' && $name === 'idx_cluster_routes_legacy_remote_task') {
            $db->exec('CREATE INDEX idx_cluster_routes_legacy_remote_task ON cluster_routes(remote_task_id)');
            continue;
        }
        if ($table === 'service_logs' && $name === 'idx_legacy_service_logs_action') {
            $db->exec('CREATE INDEX idx_legacy_service_logs_action ON service_logs(action)');
            continue;
        }
        throw new InvalidArgumentException('Unsupported SQLite rebuild index.');
    }
}

function hub_db(): PDO
{
    hub_ensure_runtime_dirs();
    $dbDir = dirname(HUB_DB_PATH);
    if (!is_dir($dbDir) && !mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
        throw new RuntimeException('Cannot create database directory.');
    }
    $db = new PDO('sqlite:' . HUB_DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA foreign_keys = ON');

    return $db;
}

function hub_voice_profile_active_sha_index_unique(PDO $db): ?bool
{
    foreach ($db->query("PRAGMA index_list('voice_profiles')")->fetchAll() as $index) {
        if (($index['name'] ?? '') === 'idx_voice_profiles_owner_sha_active') {
            return (int)($index['unique'] ?? 0) === 1;
        }
    }

    return null;
}

function hub_db_migration_is_current(PDO $db): bool
{
    try {
        $settings = $db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'settings'")->fetchColumn();
        if ($settings === false) {
            return false;
        }
        $keys = [
            HUB_DB_MIGRATION_VERSION_KEY,
            HUB_DB_MIGRATION_SCHEMA_KEY,
            'db_migration_voice_profiles_prompt_text_confirmed_at_v1',
            'db_migration_voice_profiles_transcription_state_v1',
        ];
        $stmt = $db->prepare('SELECT key, value FROM settings WHERE key IN (?, ?, ?, ?)');
        $stmt->execute($keys);
        $values = array_column($stmt->fetchAll(), 'value', 'key');
        if (
            ($values[HUB_DB_MIGRATION_VERSION_KEY] ?? '') !== HUB_DB_MIGRATION_VERSION
            || ($values[HUB_DB_MIGRATION_SCHEMA_KEY] ?? '') !== (string)$db->query('PRAGMA schema_version')->fetchColumn()
            || ($values[$keys[2]] ?? '') !== '1'
            || ($values[$keys[3]] ?? '') !== '1'
            || hub_runtime_schema_missing($db) !== []
        ) {
            return false;
        }
        if ($db->query("SELECT 1 FROM runtime_runs WHERE state = 'success' LIMIT 1")->fetchColumn() !== false) {
            return false;
        }
        if ($db->query("SELECT 1 FROM tasks WHERE status = 'timeout' LIMIT 1")->fetchColumn() !== false) {
            return false;
        }

        return $db->query("SELECT 1 FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

function hub_db_mark_migration_current(PDO $db): void
{
    hub_set_storage_setting($db, HUB_DB_MIGRATION_VERSION_KEY, HUB_DB_MIGRATION_VERSION);
    hub_set_storage_setting($db, HUB_DB_MIGRATION_SCHEMA_KEY, (string)$db->query('PRAGMA schema_version')->fetchColumn());
}

function hub_migrate(PDO $db): void
{
    if (hub_db_migration_is_current($db)) {
        if (function_exists('hub_cluster_node_reconcile_token_permissions')) {
            hub_cluster_node_reconcile_token_permissions($db);
        }
        return;
    }
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    must_change_password INTEGER NOT NULL DEFAULT 1,
    role TEXT NOT NULL DEFAULT 'system_admin',
    api_member_id INTEGER NULL,
    display_name TEXT NULL,
    email TEXT NULL,
    company TEXT NULL,
    is_protected INTEGER NOT NULL DEFAULT 0,
    is_enabled INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(api_member_id) REFERENCES api_members(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS services (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    mode TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL,
    internal_url TEXT NOT NULL,
    health_url TEXT NOT NULL,
    compose_project TEXT NOT NULL,
    compose_file TEXT NOT NULL,
    pack_id TEXT NULL,
    pack_version TEXT NULL,
    service_key TEXT NULL,
    install_status TEXT NOT NULL DEFAULT 'installed',
    runtime_status TEXT NOT NULL DEFAULT 'stopped',
    environment_json TEXT NULL,
    local_port INTEGER NULL,
    port_mode TEXT NOT NULL DEFAULT 'auto',
    hot_reload INTEGER NOT NULL DEFAULT 0,
    environment TEXT NOT NULL DEFAULT 'production',
    execution_type TEXT NOT NULL DEFAULT 'sync_api',
    config_dirty INTEGER NOT NULL DEFAULT 0,
    restart_required INTEGER NOT NULL DEFAULT 0,
    enabled INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'stopped',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS service_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NULL,
    action TEXT NOT NULL,
    output TEXT NOT NULL,
    exit_code INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS i18n (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL DEFAULT '',
    lang TEXT NOT NULL DEFAULT '',
    trans TEXT NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    action TEXT NOT NULL,
    details TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    username TEXT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    reason TEXT NULL,
    user_agent TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS login_ip_locks (
    ip TEXT PRIMARY KEY,
    failed_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT NULL,
    last_failed_at TEXT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS command_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action TEXT NOT NULL,
    service_id INTEGER NULL,
    args_json TEXT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    progress INTEGER NOT NULL DEFAULT 0,
    stage TEXT NULL,
    current_message TEXT NULL,
    requested_by INTEGER NULL,
    requested_ip TEXT NULL,
    lock_token TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    exit_code INTEGER NULL,
    stdout_path TEXT NULL,
    stderr_path TEXT NULL,
    error_message TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL,
    FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS env_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'ok',
    error_message TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS host_metric_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_json TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS benchmark_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    benchmark_key TEXT NOT NULL,
    service_id INTEGER NULL,
    mode TEXT NULL,
    status TEXT NOT NULL,
    elapsed_ms INTEGER NULL,
    result_json TEXT NULL,
    error_message TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_type TEXT NOT NULL,
    queue_name TEXT NOT NULL DEFAULT 'default',
    priority INTEGER NOT NULL DEFAULT 0,
    input_json TEXT NULL,
    result_json TEXT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    progress INTEGER NOT NULL DEFAULT 0,
    requested_by INTEGER NULL,
    requested_ip TEXT NULL,
    lock_token TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    error_message TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS task_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    level TEXT NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS task_artifacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    path TEXT NOT NULL,
    mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
    size_bytes INTEGER NOT NULL DEFAULT 0,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS task_artifact_holds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_artifact_id INTEGER NOT NULL,
    downstream_task_id INTEGER NOT NULL,
    held_at TEXT NOT NULL,
    released_at TEXT NULL,
    UNIQUE(source_artifact_id, downstream_task_id),
    FOREIGN KEY(source_artifact_id) REFERENCES task_artifacts(id) ON DELETE CASCADE,
    FOREIGN KEY(downstream_task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS facebook_crawler_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    profile_id TEXT NOT NULL UNIQUE,
    owner_member_id INTEGER NOT NULL,
    node_name TEXT NOT NULL,
    display_name TEXT NOT NULL,
    state TEXT NOT NULL DEFAULT 'preparing',
    last_verified_at TEXT NULL,
    active_task_id INTEGER NULL,
    login_secret_hash TEXT NULL,
    login_container_name TEXT NULL,
    login_port INTEGER NULL,
    login_expires_at TEXT NULL,
    deleted_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE CASCADE,
    FOREIGN KEY(active_task_id) REFERENCES tasks(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS sam3_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id TEXT NOT NULL UNIQUE,
    service_id INTEGER NOT NULL,
    display_name TEXT NOT NULL,
    protocol TEXT NOT NULL,
    source_url TEXT NOT NULL,
    clip_seconds INTEGER NOT NULL DEFAULT 15,
    monitor_enabled INTEGER NOT NULL DEFAULT 0,
    monitor_interval_seconds INTEGER NOT NULL DEFAULT 60,
    last_error_code TEXT NULL,
    last_seen_at TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS sam3_monitor_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id TEXT NOT NULL UNIQUE,
    service_id INTEGER NOT NULL,
    task_id INTEGER NOT NULL,
    runtime_run_id TEXT NOT NULL UNIQUE,
    state TEXT NOT NULL,
    last_heartbeat_at TEXT NOT NULL,
    started_at TEXT NOT NULL,
    stopped_at TEXT NULL,
    last_safe_error_code TEXT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sam3_monitor_event_artifacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    runtime_run_id TEXT NOT NULL,
    sequence INTEGER NOT NULL,
    artifact_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(runtime_run_id, sequence),
    FOREIGN KEY(artifact_id) REFERENCES task_artifacts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS service_ip_whitelists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    ip_rule TEXT NOT NULL,
    rule_type TEXT NOT NULL DEFAULT 'cidr',
    label TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS service_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    value_type TEXT NOT NULL DEFAULT 'text',
    is_secret INTEGER NOT NULL DEFAULT 0,
    restart_required INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(service_id, key),
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS api_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    contact_name TEXT NULL,
    contact_email TEXT NULL,
    note TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    token_name TEXT NOT NULL,
    token_prefix TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    enabled INTEGER NOT NULL DEFAULT 1,
    valid_from TEXT NULL,
    valid_until TEXT NULL,
    last_used_at TEXT NULL,
    last_used_ip TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    FOREIGN KEY(member_id) REFERENCES api_members(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS api_token_service_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_id INTEGER NOT NULL,
    service_id INTEGER NULL,
    mode TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(token_id, mode),
    FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE CASCADE,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS api_token_ip_whitelists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_id INTEGER NOT NULL,
    ip_rule TEXT NOT NULL,
    rule_type TEXT NOT NULL DEFAULT 'cidr',
    label TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(token_id, ip_rule),
    FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS api_token_usage_daily (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    mode TEXT NOT NULL,
    usage_date TEXT NOT NULL,
    request_count INTEGER NOT NULL DEFAULT 0,
    success_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,
    total_elapsed_ms INTEGER NOT NULL DEFAULT 0,
    total_upload_bytes INTEGER NOT NULL DEFAULT 0,
    total_response_bytes INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(token_id, mode, usage_date),
    FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE CASCADE,
    FOREIGN KEY(member_id) REFERENCES api_members(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS api_access_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NULL,
    service_id INTEGER NULL,
    member_id INTEGER NULL,
    token_id INTEGER NULL,
    mode TEXT NULL,
    client_ip TEXT NOT NULL,
    method TEXT NOT NULL,
    request_uri TEXT NOT NULL,
    status_code INTEGER NOT NULL,
    ok INTEGER NOT NULL DEFAULT 0,
    error_code TEXT NULL,
    reason TEXT NULL,
    user_agent TEXT NULL,
    elapsed_ms INTEGER NULL,
    upload_bytes INTEGER NULL,
    response_bytes INTEGER NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL,
    FOREIGN KEY(member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS voice_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_member_id INTEGER NOT NULL,
    source_task_id INTEGER NULL,
    name TEXT NOT NULL,
    reference_audio_path TEXT NOT NULL,
    reference_audio_sha256 TEXT NOT NULL,
    reference_contract TEXT NOT NULL DEFAULT 'generic',
    prompt_text TEXT NULL,
    transcript_validation_json TEXT NULL,
    prompt_text_confirmed_at TEXT NULL,
    language TEXT NULL,
    transcription_status TEXT NOT NULL DEFAULT 'pending',
    transcription_error TEXT NULL,
    transcription_started_at TEXT NULL,
    transcription_lease_token TEXT NULL,
    consent_type TEXT NOT NULL,
    usage_scope TEXT NOT NULL DEFAULT 'private',
    visibility TEXT NOT NULL DEFAULT 'private',
    retain_original_audio INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT NULL,
    deleted_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS voice_profile_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    voice_profile_id INTEGER NULL,
    owner_member_id INTEGER NULL,
    token_id INTEGER NULL,
    action TEXT NOT NULL,
    mode TEXT NULL,
    details_json TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(voice_profile_id) REFERENCES voice_profiles(id) ON DELETE SET NULL,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS playground_tts_artifacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    service_id INTEGER NULL,
    owner_member_id INTEGER NOT NULL,
    request_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(service_id, filename),
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS photo_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    image_id TEXT NOT NULL UNIQUE,
    owner_member_id INTEGER NULL,
    owner_token_id INTEGER NULL,
    mime TEXT NOT NULL,
    byte_size INTEGER NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    storage_relpath TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    last_accessed_at TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(owner_token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS audio_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    audio_id TEXT NOT NULL UNIQUE,
    owner_member_id INTEGER NULL,
    owner_token_id INTEGER NULL,
    mime TEXT NOT NULL,
    byte_size INTEGER NOT NULL,
    duration_ms INTEGER NOT NULL,
    sample_rate INTEGER NOT NULL,
    channels INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    storage_relpath TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    last_accessed_at TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(owner_token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS user_mode_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    service_id INTEGER NULL,
    mode TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(user_id, mode),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS yolo_model_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    model_ref TEXT NOT NULL UNIQUE,
    source_system TEXT NOT NULL,
    external_model_key TEXT NOT NULL,
    version INTEGER NOT NULL,
    display_name TEXT NOT NULL,
    task_type TEXT NOT NULL DEFAULT 'detect',
    framework TEXT NOT NULL DEFAULT 'ultralytics',
    framework_version TEXT NULL,
    artifact_path TEXT NOT NULL,
    artifact_size_bytes INTEGER NOT NULL DEFAULT 0,
    sha256 TEXT NOT NULL,
    imgsz INTEGER NULL,
    class_count INTEGER NULL,
    labels_json TEXT NULL,
    metadata_json TEXT NULL,
    source_run_id TEXT NULL,
    validation_status TEXT NOT NULL DEFAULT 'registered',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(source_system, external_model_key, sha256)
);

CREATE TABLE IF NOT EXISTS yolo_model_deployments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    model_version_id INTEGER NOT NULL,
    service_key TEXT NOT NULL,
    slot_no INTEGER NOT NULL,
    actual_state TEXT NOT NULL DEFAULT 'queued',
    warm_run_id TEXT NULL,
    vram_bytes INTEGER NULL,
    load_duration_ms INTEGER NULL,
    warm_inference_ms INTEGER NULL,
    loaded_at TEXT NULL,
    last_used_at TEXT NULL,
    last_error_code TEXT NULL,
    last_error_message TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(service_key, slot_no),
    UNIQUE(service_key, model_version_id),
    FOREIGN KEY(model_version_id) REFERENCES yolo_model_versions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS runtime_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id TEXT NOT NULL UNIQUE,
    pack_id TEXT NOT NULL,
    task TEXT NOT NULL,
    pack_version TEXT NULL,
    runner_version TEXT NULL,
    image_name TEXT NULL,
    image_digest TEXT NULL,
    container_id TEXT NULL,
    caller TEXT NULL,
    workspace TEXT NULL,
    state TEXT NOT NULL,
    worker_id TEXT NULL,
    lease_token TEXT NULL,
    lease_expires_at TEXT NULL,
    heartbeat_at TEXT NULL,
    claimed_at TEXT NULL,
    recovery_count INTEGER NOT NULL DEFAULT 0,
    last_recovered_at TEXT NULL,
    last_recovery_reason TEXT NULL,
    cancel_requested_at TEXT NULL,
    cancel_reason TEXT NULL,
    timeout_at TEXT NULL,
    cancelled_at TEXT NULL,
    exit_code INTEGER NULL,
    error_code TEXT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT NULL,
    duration_ms INTEGER NULL,
    cpu_time_ms INTEGER NULL,
    cpu_peak_percent REAL NULL,
    memory_peak_bytes INTEGER NULL,
    gpu_indexes TEXT NULL,
    gpu_util_peak_percent REAL NULL,
    gpu_util_avg_percent REAL NULL,
    vram_peak_bytes INTEGER NULL,
    disk_read_bytes INTEGER NULL,
    disk_write_bytes INTEGER NULL,
    network_rx_bytes INTEGER NULL,
    network_tx_bytes INTEGER NULL,
    artifact_count INTEGER NOT NULL DEFAULT 0,
    log_size_bytes INTEGER NOT NULL DEFAULT 0,
    result_json_path TEXT NULL,
    stdout_log_path TEXT NULL,
    stderr_log_path TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS runtime_resource_samples (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id TEXT NOT NULL,
    sampled_at TEXT NOT NULL,
    cpu_percent REAL NULL,
    cpu_time_ms INTEGER NULL,
    memory_bytes INTEGER NULL,
    process_count INTEGER NULL,
    disk_read_bytes INTEGER NULL,
    disk_write_bytes INTEGER NULL,
    network_rx_bytes INTEGER NULL,
    network_tx_bytes INTEGER NULL,
    gpu_json TEXT NULL,
    FOREIGN KEY(run_id) REFERENCES runtime_runs(run_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS task_callback_targets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_member_id INTEGER NOT NULL,
    target_alias TEXT NOT NULL,
    callback_url TEXT NOT NULL,
    signing_secret TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(owner_member_id, target_alias),
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS task_callback_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    delivery_id TEXT NOT NULL UNIQUE,
    callback_target_id INTEGER NOT NULL,
    task_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NULL,
    claim_token TEXT NULL,
    claim_expires_at TEXT NULL,
    delivered_at TEXT NULL,
    last_http_status INTEGER NULL,
    last_error TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(callback_target_id, task_id, event_type),
    FOREIGN KEY(callback_target_id) REFERENCES task_callback_targets(id) ON DELETE CASCADE,
    FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS runtime_resource_leases (
    resource_key TEXT PRIMARY KEY,
    runtime_run_id TEXT NULL,
    worker_id TEXT NULL,
    lease_token TEXT NULL,
    state TEXT NOT NULL,
    acquired_at TEXT NULL,
    heartbeat_at TEXT NULL,
    lease_expires_at TEXT NULL,
    last_error TEXT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(runtime_run_id) REFERENCES runtime_runs(run_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS resident_job_runs (
    runtime_run_id TEXT PRIMARY KEY,
    task_id INTEGER NOT NULL,
    service_id INTEGER NOT NULL,
    resident_run_id TEXT NOT NULL UNIQUE,
    lifecycle TEXT NOT NULL,
    dispatched_at TEXT NOT NULL,
    cancel_requested_at TEXT NULL,
    unconfirmed_at TEXT NULL,
    reconciled_at TEXT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(runtime_run_id) REFERENCES runtime_runs(run_id) ON DELETE CASCADE,
    FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cluster_stations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_key TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    public_base_url TEXT NOT NULL,
    internal_base_url TEXT NULL,
    priority INTEGER NOT NULL DEFAULT 0,
    enabled INTEGER NOT NULL DEFAULT 1,
    token_ciphertext TEXT NOT NULL,
    token_iv TEXT NOT NULL,
    token_tag TEXT NOT NULL,
    manifest_json TEXT NULL,
    manifest_fetched_at TEXT NULL,
    status_json TEXT NULL,
    status_fetched_at TEXT NULL,
    last_error TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS cluster_gpu_metric_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL,
    sampled_at TEXT NOT NULL,
    gpu_json TEXT NOT NULL,
    UNIQUE(station_id, sampled_at),
    FOREIGN KEY(station_id) REFERENCES cluster_stations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cluster_routes (
    route_id TEXT NOT NULL PRIMARY KEY,
    station_id INTEGER NOT NULL,
    member_id INTEGER NULL,
    token_id INTEGER NULL,
    mode TEXT NOT NULL,
    route_role TEXT NOT NULL DEFAULT 'task',
    remote_task_id TEXT NULL,
    is_async INTEGER NOT NULL DEFAULT 0,
    state TEXT NOT NULL,
    remote_status TEXT NULL,
    expires_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT NULL,
    FOREIGN KEY(station_id) REFERENCES cluster_stations(id) ON DELETE CASCADE,
    FOREIGN KEY(member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS cluster_route_accesses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    route_id TEXT NOT NULL,
    station_id INTEGER NULL,
    member_id INTEGER NULL,
    token_id INTEGER NULL,
    mode TEXT NOT NULL,
    access_kind TEXT NOT NULL,
    request_id TEXT NULL,
    status_code INTEGER NOT NULL,
    ok INTEGER NOT NULL DEFAULT 0,
    error_code TEXT NULL,
    elapsed_ms INTEGER NOT NULL DEFAULT 0,
    upload_bytes INTEGER NOT NULL DEFAULT 0,
    response_bytes INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    FOREIGN KEY(route_id) REFERENCES cluster_routes(route_id) ON DELETE CASCADE,
    FOREIGN KEY(station_id) REFERENCES cluster_stations(id) ON DELETE SET NULL,
    FOREIGN KEY(member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS cluster_route_artifacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    route_id TEXT NOT NULL,
    remote_artifact_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(route_id, remote_artifact_id),
    FOREIGN KEY(route_id) REFERENCES cluster_routes(route_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cluster_photo_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    image_id TEXT NOT NULL UNIQUE,
    station_id INTEGER NOT NULL,
    remote_image_id TEXT NOT NULL,
    owner_member_id INTEGER NULL,
    owner_token_id INTEGER NULL,
    expires_at TEXT NOT NULL,
    last_accessed_at TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(station_id) REFERENCES cluster_stations(id) ON DELETE CASCADE,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(owner_token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);
SQL);

    hub_migrate_cluster_routes_route_id_not_null($db);
    $db->exec(
        'CREATE TABLE IF NOT EXISTS sam3_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_id TEXT NOT NULL UNIQUE,
            service_id INTEGER NOT NULL,
            display_name TEXT NOT NULL,
            protocol TEXT NOT NULL,
            source_url TEXT NOT NULL,
            clip_seconds INTEGER NOT NULL DEFAULT 15,
            monitor_enabled INTEGER NOT NULL DEFAULT 0,
            monitor_interval_seconds INTEGER NOT NULL DEFAULT 60,
            last_error_code TEXT NULL,
            last_seen_at TEXT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS sam3_monitor_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_id TEXT NOT NULL UNIQUE,
            service_id INTEGER NOT NULL,
            task_id INTEGER NOT NULL,
            runtime_run_id TEXT NOT NULL UNIQUE,
            state TEXT NOT NULL,
            last_heartbeat_at TEXT NOT NULL,
            started_at TEXT NOT NULL,
            stopped_at TEXT NULL,
            last_safe_error_code TEXT NULL,
            FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
            FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS sam3_monitor_event_artifacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            runtime_run_id TEXT NOT NULL,
            sequence INTEGER NOT NULL,
            artifact_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(runtime_run_id, sequence),
            FOREIGN KEY(artifact_id) REFERENCES task_artifacts(id) ON DELETE CASCADE
        )'
    );
    hub_add_column_if_missing($db, 'cluster_routes', 'route_role', "TEXT NOT NULL DEFAULT 'task'");
    hub_add_column_if_missing($db, 'users', 'role', "TEXT NOT NULL DEFAULT 'system_admin'");
    hub_add_column_if_missing($db, 'users', 'api_member_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'users', 'display_name', 'TEXT NULL');
    hub_add_column_if_missing($db, 'users', 'email', 'TEXT NULL');
    hub_add_column_if_missing($db, 'users', 'company', 'TEXT NULL');
    hub_add_column_if_missing($db, 'users', 'is_protected', 'INTEGER NOT NULL DEFAULT 0');
    hub_add_column_if_missing($db, 'users', 'is_enabled', 'INTEGER NOT NULL DEFAULT 1');
    hub_add_column_if_missing($db, 'users', 'last_login_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'services', 'local_port', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'services', 'port_mode', "TEXT NOT NULL DEFAULT 'auto'");
    hub_add_column_if_missing($db, 'services', 'hot_reload', 'INTEGER NOT NULL DEFAULT 0');
    hub_add_column_if_missing($db, 'services', 'environment', "TEXT NOT NULL DEFAULT 'production'");
    hub_add_column_if_missing($db, 'services', 'execution_type', "TEXT NOT NULL DEFAULT 'sync_api'");
    hub_add_column_if_missing($db, 'services', 'pack_id', 'TEXT NULL');
    hub_add_column_if_missing($db, 'services', 'pack_version', 'TEXT NULL');
    hub_add_column_if_missing($db, 'services', 'service_key', 'TEXT NULL');
    hub_add_column_if_missing($db, 'services', 'install_status', "TEXT NOT NULL DEFAULT 'installed'");
    hub_add_column_if_missing($db, 'services', 'runtime_status', "TEXT NOT NULL DEFAULT 'stopped'");
    hub_add_column_if_missing($db, 'services', 'environment_json', 'TEXT NULL');
    hub_add_column_if_missing($db, 'services', 'config_dirty', 'INTEGER NOT NULL DEFAULT 0');
    hub_add_column_if_missing($db, 'services', 'restart_required', 'INTEGER NOT NULL DEFAULT 0');
    hub_add_column_if_missing($db, 'api_access_logs', 'request_id', 'TEXT NULL');
    hub_add_column_if_missing($db, 'api_access_logs', 'member_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'api_access_logs', 'token_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'api_access_logs', 'upload_bytes', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'api_access_logs', 'response_bytes', 'INTEGER NULL');
    $voiceProfilePromptConfirmationMarker = 'db_migration_voice_profiles_prompt_text_confirmed_at_v1';
    if (hub_get_storage_setting($db, $voiceProfilePromptConfirmationMarker) !== '1') {
        $voiceProfilePromptConfirmationMigrationStarted = false;
        try {
            $db->exec('BEGIN IMMEDIATE');
            $voiceProfilePromptConfirmationMigrationStarted = true;
            hub_add_column_if_missing($db, 'voice_profiles', 'prompt_text_confirmed_at', 'TEXT NULL');
            if (hub_get_storage_setting($db, $voiceProfilePromptConfirmationMarker) !== '1') {
                $db->exec("UPDATE voice_profiles
                           SET prompt_text_confirmed_at = COALESCE(prompt_text_confirmed_at, updated_at)
                           WHERE prompt_text IS NOT NULL AND trim(prompt_text) <> ''");
                hub_set_storage_setting($db, $voiceProfilePromptConfirmationMarker, '1');
            }
            $db->exec('COMMIT');
            $voiceProfilePromptConfirmationMigrationStarted = false;
        } catch (Throwable $e) {
            if ($voiceProfilePromptConfirmationMigrationStarted) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    } else {
        hub_add_column_if_missing($db, 'voice_profiles', 'prompt_text_confirmed_at', 'TEXT NULL');
    }
    hub_add_column_if_missing($db, 'voice_profiles', 'usage_scope', "TEXT NOT NULL DEFAULT 'private'");
    hub_add_column_if_missing($db, 'voice_profiles', 'retain_original_audio', 'INTEGER NOT NULL DEFAULT 1');
    hub_add_column_if_missing($db, 'voice_profiles', 'source_task_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'voice_profiles', 'reference_contract', "TEXT NOT NULL DEFAULT 'generic'");
    hub_add_column_if_missing($db, 'voice_profiles', 'transcript_validation_json', 'TEXT NULL');
    $voiceProfileTranscriptionStateMarker = 'db_migration_voice_profiles_transcription_state_v1';
    if (hub_get_storage_setting($db, $voiceProfileTranscriptionStateMarker) !== '1') {
        $voiceProfileTranscriptionStateMigrationStarted = false;
        try {
            $db->exec('BEGIN IMMEDIATE');
            $voiceProfileTranscriptionStateMigrationStarted = true;
            hub_add_column_if_missing($db, 'voice_profiles', 'transcription_status', "TEXT NOT NULL DEFAULT 'pending'");
            hub_add_column_if_missing($db, 'voice_profiles', 'transcription_error', 'TEXT NULL');
            hub_add_column_if_missing($db, 'voice_profiles', 'transcription_started_at', 'TEXT NULL');
            hub_add_column_if_missing($db, 'voice_profiles', 'transcription_lease_token', 'TEXT NULL');
            if (hub_get_storage_setting($db, $voiceProfileTranscriptionStateMarker) !== '1') {
                $db->exec("UPDATE voice_profiles
                           SET transcription_status = CASE
                               WHEN prompt_text IS NOT NULL AND trim(prompt_text) <> '' THEN 'ready'
                               WHEN transcription_error IS NOT NULL AND trim(transcription_error) <> '' THEN 'failed'
                               ELSE 'pending'
                           END,
                               transcription_error = CASE
                               WHEN transcription_error = 'asr_unavailable' THEN 'asr_unavailable'
                               WHEN transcription_error IS NOT NULL AND trim(transcription_error) <> '' THEN 'asr_failed'
                               ELSE NULL
                           END");
                hub_set_storage_setting($db, $voiceProfileTranscriptionStateMarker, '1');
            }
            $db->exec('COMMIT');
            $voiceProfileTranscriptionStateMigrationStarted = false;
        } catch (Throwable $e) {
            if ($voiceProfileTranscriptionStateMigrationStarted) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    } else {
        hub_add_column_if_missing($db, 'voice_profiles', 'transcription_status', "TEXT NOT NULL DEFAULT 'pending'");
        hub_add_column_if_missing($db, 'voice_profiles', 'transcription_error', 'TEXT NULL');
        hub_add_column_if_missing($db, 'voice_profiles', 'transcription_started_at', 'TEXT NULL');
        hub_add_column_if_missing($db, 'voice_profiles', 'transcription_lease_token', 'TEXT NULL');
    }
    $db->exec('DROP TABLE IF EXISTS voice_profile_upload_locks');
    hub_add_column_if_missing($db, 'command_jobs', 'stderr_path', 'TEXT NULL');
    hub_add_column_if_missing($db, 'command_jobs', 'progress', 'INTEGER NOT NULL DEFAULT 0');
    hub_add_column_if_missing($db, 'command_jobs', 'stage', 'TEXT NULL');
    hub_add_column_if_missing($db, 'command_jobs', 'current_message', 'TEXT NULL');
    hub_add_column_if_missing($db, 'command_jobs', 'error_code', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'owner_member_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'tasks', 'owner_token_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'tasks', 'requested_mode', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'pack_id', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'pack_version', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'job', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'job_contract_json', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'job_contract_digest', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'runtime_mode', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'accelerator', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'route_resolved_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'source_artifact_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'tasks', 'source_task_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'tasks', 'retry_of_task_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'tasks', 'callback_target_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'tasks', 'waiting_reason', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'next_attempt_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'waiting_detail_json', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'error_code', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'source_expires_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'workspace_expires_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'source_state', "TEXT NOT NULL DEFAULT 'available'");
    hub_add_column_if_missing($db, 'tasks', 'workspace_state', "TEXT NOT NULL DEFAULT 'active'");
    hub_add_column_if_missing($db, 'tasks', 'retention_state', "TEXT NOT NULL DEFAULT 'active'");
    hub_add_column_if_missing($db, 'tasks', 'purged_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'freed_bytes', 'INTEGER NOT NULL DEFAULT 0');
    hub_add_column_if_missing($db, 'tasks', 'purge_claim_token', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'purge_claimed_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'purge_error', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'metadata_purge_claim_token', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'metadata_purge_claimed_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'partial_purge_error', 'TEXT NULL');
    hub_add_column_if_missing($db, 'tasks', 'partial_purge_retry_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_callback_deliveries', 'claim_token', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_callback_deliveries', 'claim_expires_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'artifact_type', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'sha256', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'metadata_json', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'expires_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'state', "TEXT NOT NULL DEFAULT 'available'");
    hub_add_column_if_missing($db, 'task_artifacts', 'pinned_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'legal_hold', 'INTEGER NOT NULL DEFAULT 0');
    hub_add_column_if_missing($db, 'task_artifacts', 'acknowledged_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'last_accessed_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'purged_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'purge_error', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'purge_claim_token', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'purge_claimed_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'download_claim_token', 'TEXT NULL');
    hub_add_column_if_missing($db, 'task_artifacts', 'download_claim_expires_at', 'TEXT NULL');
    hub_migrate_service_logs_service_reference($db);
    hub_migrate_playground_tts_artifacts_service_reference($db);
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_services_service_key ON services(service_key) WHERE service_key IS NOT NULL');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_services_local_port ON services(local_port) WHERE local_port IS NOT NULL');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_service_ip_whitelists_unique ON service_ip_whitelists(service_id, ip_rule)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_created_at ON api_access_logs(created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_client_ip ON api_access_logs(client_ip)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_mode ON api_access_logs(mode)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_service_id ON api_access_logs(service_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_ok ON api_access_logs(ok)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_error_code ON api_access_logs(error_code)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_request_id ON api_access_logs(request_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_i18n_lookup ON i18n(lang, title, id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_member_id ON api_access_logs(member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_token_id ON api_access_logs(token_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_created ON login_attempts(ip, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_tokens_member_id ON api_tokens(member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_token_permissions_token_id ON api_token_service_permissions(token_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_token_permissions_mode ON api_token_service_permissions(mode)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_token_ip_rules_token_id ON api_token_ip_whitelists(token_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_token_usage_member_date ON api_token_usage_daily(member_id, usage_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_token_usage_token_date ON api_token_usage_daily(token_id, usage_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_voice_profiles_owner ON voice_profiles(owner_member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_voice_profiles_deleted ON voice_profiles(deleted_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_voice_profiles_source_task ON voice_profiles(source_task_id)');
    $voiceProfileIndexUnique = hub_voice_profile_active_sha_index_unique($db);
    if ($voiceProfileIndexUnique === null) {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_voice_profiles_owner_sha_active ON voice_profiles(owner_member_id, reference_audio_sha256) WHERE deleted_at IS NULL');
    } elseif ($voiceProfileIndexUnique) {
        $voiceProfileIndexMigrationStarted = false;
        try {
            $db->exec('BEGIN IMMEDIATE');
            $voiceProfileIndexMigrationStarted = true;
            $voiceProfileIndexUnique = hub_voice_profile_active_sha_index_unique($db);
            if ($voiceProfileIndexUnique === null) {
                $db->exec('CREATE INDEX IF NOT EXISTS idx_voice_profiles_owner_sha_active ON voice_profiles(owner_member_id, reference_audio_sha256) WHERE deleted_at IS NULL');
            } elseif ($voiceProfileIndexUnique) {
                $db->exec('DROP INDEX idx_voice_profiles_owner_sha_active');
                $db->exec('CREATE INDEX idx_voice_profiles_owner_sha_active ON voice_profiles(owner_member_id, reference_audio_sha256) WHERE deleted_at IS NULL');
            }
            $db->exec('COMMIT');
            $voiceProfileIndexMigrationStarted = false;
        } catch (Throwable $e) {
            if ($voiceProfileIndexMigrationStarted) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_voice_profile_audit_profile ON voice_profile_audit_logs(voice_profile_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_voice_profile_audit_owner ON voice_profile_audit_logs(owner_member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_playground_tts_artifacts_owner ON playground_tts_artifacts(owner_member_id, service_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_photo_assets_expires_at ON photo_assets(expires_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_photo_assets_sha256 ON photo_assets(sha256)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_photo_assets_owner_member ON photo_assets(owner_member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_photo_assets_owner_token ON photo_assets(owner_token_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_photo_assets_expires_at ON cluster_photo_assets(expires_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_photo_assets_owner_member ON cluster_photo_assets(owner_member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_photo_assets_owner_token ON cluster_photo_assets(owner_token_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_audio_assets_expires_at ON audio_assets(expires_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_audio_assets_sha256 ON audio_assets(sha256)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_audio_assets_owner_member ON audio_assets(owner_member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_audio_assets_owner_token ON audio_assets(owner_token_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_service_settings_service_id ON service_settings(service_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_users_api_member_id ON users(api_member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_user_mode_permissions_user_id ON user_mode_permissions(user_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_user_mode_permissions_mode ON user_mode_permissions(mode)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_yolo_model_versions_source ON yolo_model_versions(source_system, external_model_key)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_yolo_model_versions_sha256 ON yolo_model_versions(sha256)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_yolo_model_deployments_state ON yolo_model_deployments(service_key, actual_state)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_yolo_model_deployments_model ON yolo_model_deployments(model_version_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_runtime_samples_run_time ON runtime_resource_samples(run_id, sampled_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_runtime_runs_started ON runtime_runs(started_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_runtime_runs_pack ON runtime_runs(pack_id, started_at)');
    hub_add_column_if_missing($db, 'runtime_runs', 'worker_id', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'lease_token', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'lease_expires_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'heartbeat_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'claimed_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'recovery_count', 'INTEGER NOT NULL DEFAULT 0');
    hub_add_column_if_missing($db, 'runtime_runs', 'last_recovered_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'last_recovery_reason', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'cancel_requested_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'cancel_reason', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'timeout_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'cancelled_at', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'task_id', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'attempt_no', 'INTEGER NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'container_id', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'gpu_process_baseline_json', 'TEXT NULL');
    hub_add_column_if_missing($db, 'runtime_runs', 'owned_gpu_pids_json', 'TEXT NULL');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_runtime_runs_claim ON runtime_runs(state, id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_runtime_runs_stale ON runtime_runs(state, lease_expires_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_task_callback_targets_alias ON task_callback_targets(target_alias)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_task_callback_deliveries_due ON task_callback_deliveries(delivered_at, next_attempt_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_task_callback_deliveries_claim ON task_callback_deliveries(delivered_at, claim_expires_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_task_callback_deliveries_task_id ON task_callback_deliveries(task_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_task_artifact_holds_active ON task_artifact_holds(source_artifact_id, released_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_facebook_profiles_owner ON facebook_crawler_profiles(owner_member_id, deleted_at, updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_facebook_profiles_login_expiry ON facebook_crawler_profiles(login_expires_at) WHERE login_expires_at IS NOT NULL');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sam3_sources_service_enabled ON sam3_sources(service_id, enabled, updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sam3_monitor_runs_state ON sam3_monitor_runs(state, last_heartbeat_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sam3_monitor_events_run ON sam3_monitor_event_artifacts(runtime_run_id, sequence)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_task_artifacts_retention ON task_artifacts(state, expires_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_task_artifacts_download_claim ON task_artifacts(download_claim_expires_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tasks_metadata_retention ON tasks(status, finished_at, metadata_purge_claim_token)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tasks_partial_candidates ON tasks(status, updated_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tasks_partial_retry ON tasks(partial_purge_retry_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_runtime_resource_leases_state_expires ON runtime_resource_leases(state, lease_expires_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_runtime_resource_leases_run_id ON runtime_resource_leases(runtime_run_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_resident_job_runs_lifecycle ON resident_job_runs(lifecycle, updated_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_resident_job_runs_task ON resident_job_runs(task_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_stations_enabled ON cluster_stations(enabled, priority DESC)');
    $db->exec('DROP INDEX IF EXISTS idx_cluster_gpu_metric_snapshots_station_time');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_gpu_metric_snapshots_sampled_at ON cluster_gpu_metric_snapshots(sampled_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_routes_station_state ON cluster_routes(station_id, state, updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_routes_member_token ON cluster_routes(member_id, token_id, created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_route_accesses_route ON cluster_route_accesses(route_id, created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_route_accesses_usage ON cluster_route_accesses(member_id, token_id, access_kind, created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_route_accesses_station_usage ON cluster_route_accesses(station_id, mode, member_id, token_id, created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_route_accesses_mode_usage ON cluster_route_accesses(mode, station_id, member_id, token_id, created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cluster_route_artifacts_route ON cluster_route_artifacts(route_id)');
    $db->prepare(
        "INSERT OR IGNORE INTO runtime_resource_leases (resource_key, state, updated_at) VALUES ('gpu:0', 'available', :updated_at)"
    )->execute([':updated_at' => hub_now()]);
    $db->exec("UPDATE runtime_runs SET state = 'succeeded' WHERE state = 'success'");
    $db->exec("UPDATE tasks SET status = 'timed_out' WHERE status = 'timeout'");
    if (function_exists('hub_cluster_node_reconcile_token_permissions')) {
        hub_cluster_node_reconcile_token_permissions($db);
    }
    hub_db_mark_migration_current($db);
}

function hub_migrate_service_logs_service_reference(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(service_logs)')->fetchAll(), null, 'name');
    $expectedColumns = ['id', 'service_id', 'action', 'output', 'exit_code', 'created_at'];
    if (count($columns) !== count($expectedColumns) || array_diff($expectedColumns, array_keys($columns)) !== []) {
        throw new RuntimeException('Service log schema is invalid.');
    }
    $serviceKey = null;
    foreach ($db->query('PRAGMA foreign_key_list(service_logs)')->fetchAll() as $foreignKey) {
        if (($foreignKey['from'] ?? '') === 'service_id') {
            $serviceKey = $foreignKey;
            break;
        }
    }
    if (
        (int)$columns['service_id']['notnull'] === 0
        && ($serviceKey['table'] ?? '') === 'services'
        && ($serviceKey['on_delete'] ?? '') === 'SET NULL'
    ) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Service log migration requires no active transaction.');
    }

    $indexes = hub_sqlite_capture_rebuild_indexes($db, 'service_logs', $expectedColumns);
    $foreignKeysEnabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    if ($foreignKeysEnabled) {
        $db->exec('PRAGMA foreign_keys = OFF');
    }

    $started = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $started = true;
        $db->exec(<<<'SQL'
CREATE TABLE service_logs_rebuild (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NULL,
    action TEXT NOT NULL,
    output TEXT NOT NULL,
    exit_code INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL
);

INSERT INTO service_logs_rebuild (id, service_id, action, output, exit_code, created_at)
SELECT id, service_id, action, output, exit_code, created_at
FROM service_logs;

DROP TABLE service_logs;
ALTER TABLE service_logs_rebuild RENAME TO service_logs;
SQL);
        hub_sqlite_restore_rebuild_indexes($db, 'service_logs', $indexes);
        $db->exec('COMMIT');
        $started = false;
    } catch (Throwable $e) {
        if ($started) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    } finally {
        if ($foreignKeysEnabled) {
            $db->exec('PRAGMA foreign_keys = ON');
        }
    }
}

function hub_migrate_playground_tts_artifacts_service_reference(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(playground_tts_artifacts)')->fetchAll(), null, 'name');
    $expectedColumns = ['id', 'filename', 'service_id', 'owner_member_id', 'request_id', 'created_at', 'updated_at'];
    foreach ($expectedColumns as $column) {
        if (!isset($columns[$column])) {
            throw new RuntimeException('Playground TTS artifact schema is invalid.');
        }
    }
    $foreignKeys = $db->query('PRAGMA foreign_key_list(playground_tts_artifacts)')->fetchAll();
    $serviceKey = null;
    $ownerKey = null;
    foreach ($foreignKeys as $foreignKey) {
        if (($foreignKey['from'] ?? '') === 'service_id') {
            $serviceKey = $foreignKey;
        }
        if (($foreignKey['from'] ?? '') === 'owner_member_id') {
            $ownerKey = $foreignKey;
        }
    }
    if (
        (int)$columns['service_id']['notnull'] === 0
        && ($serviceKey['table'] ?? '') === 'services'
        && ($serviceKey['on_delete'] ?? '') === 'SET NULL'
        && ($ownerKey['table'] ?? '') === 'api_members'
        && ($ownerKey['on_delete'] ?? '') === 'CASCADE'
    ) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Playground TTS artifact migration requires no active transaction.');
    }

    $indexes = hub_sqlite_capture_rebuild_indexes($db, 'playground_tts_artifacts', $expectedColumns);
    $foreignKeysEnabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    if ($foreignKeysEnabled) {
        $db->exec('PRAGMA foreign_keys = OFF');
    }

    $started = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $started = true;
        $db->exec(<<<'SQL'
CREATE TABLE playground_tts_artifacts_rebuild (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    service_id INTEGER NULL,
    owner_member_id INTEGER NOT NULL,
    request_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(service_id, filename),
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE CASCADE
);

INSERT INTO playground_tts_artifacts_rebuild (
    id, filename, service_id, owner_member_id, request_id, created_at, updated_at
)
SELECT
    id, filename, service_id, owner_member_id, request_id, created_at, updated_at
FROM playground_tts_artifacts;

DROP TABLE playground_tts_artifacts;
ALTER TABLE playground_tts_artifacts_rebuild RENAME TO playground_tts_artifacts;
SQL);
        hub_sqlite_restore_rebuild_indexes($db, 'playground_tts_artifacts', $indexes);
        $db->exec('COMMIT');
        $started = false;
    } catch (Throwable $e) {
        if ($started) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    } finally {
        if ($foreignKeysEnabled) {
            $db->exec('PRAGMA foreign_keys = ON');
        }
    }
}

function hub_migrate_cluster_routes_route_id_not_null(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(cluster_routes)')->fetchAll(), null, 'name');
    if (!isset($columns['route_id'])) {
        throw new RuntimeException('Cluster routes schema is invalid.');
    }
    if ((int)$columns['route_id']['notnull'] === 1) {
        return;
    }
    if ((int)$db->query('SELECT COUNT(*) FROM cluster_routes WHERE route_id IS NULL')->fetchColumn() > 0) {
        throw new RuntimeException('Cluster route migration requires non-null route IDs.');
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Cluster route migration requires no active transaction.');
    }

    $indexes = hub_sqlite_capture_rebuild_indexes($db, 'cluster_routes', [
        'route_id', 'station_id', 'member_id', 'token_id', 'mode', 'remote_task_id', 'is_async', 'state',
        'remote_status', 'expires_at', 'created_at', 'updated_at', 'completed_at',
    ]);
    $foreignKeysEnabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    if ($foreignKeysEnabled) {
        $db->exec('PRAGMA foreign_keys = OFF');
    }

    $started = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $started = true;
        $db->exec(<<<'SQL'
CREATE TABLE cluster_routes_rebuild (
    route_id TEXT NOT NULL PRIMARY KEY,
    station_id INTEGER NOT NULL,
    member_id INTEGER NULL,
    token_id INTEGER NULL,
    mode TEXT NOT NULL,
    route_role TEXT NOT NULL DEFAULT 'task',
    remote_task_id TEXT NULL,
    is_async INTEGER NOT NULL DEFAULT 0,
    state TEXT NOT NULL,
    remote_status TEXT NULL,
    expires_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT NULL,
    FOREIGN KEY(station_id) REFERENCES cluster_stations(id) ON DELETE CASCADE,
    FOREIGN KEY(member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);

INSERT INTO cluster_routes_rebuild (
    route_id, station_id, member_id, token_id, mode, remote_task_id, is_async, state,
    remote_status, expires_at, created_at, updated_at, completed_at
)
SELECT
    route_id, station_id, member_id, token_id, mode, remote_task_id, is_async, state,
    remote_status, expires_at, created_at, updated_at, completed_at
FROM cluster_routes;

DROP TABLE cluster_routes;
ALTER TABLE cluster_routes_rebuild RENAME TO cluster_routes;
SQL);
        hub_sqlite_restore_rebuild_indexes($db, 'cluster_routes', $indexes);
        $db->exec('COMMIT');
        $started = false;
    } catch (Throwable $e) {
        if ($started) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    } finally {
        if ($foreignKeysEnabled) {
            $db->exec('PRAGMA foreign_keys = ON');
        }
    }
}

function hub_runtime_schema_missing(PDO $db): array
{
    $required = [
        'task_callback_targets' => ['id', 'owner_member_id', 'target_alias', 'callback_url', 'signing_secret', 'enabled', 'created_at', 'updated_at'],
        'task_callback_deliveries' => ['id', 'delivery_id', 'callback_target_id', 'task_id', 'event_type', 'payload_json', 'attempt_count', 'next_attempt_at', 'claim_token', 'claim_expires_at', 'delivered_at', 'last_http_status', 'last_error', 'created_at', 'updated_at'],
        'runtime_resource_leases' => ['resource_key', 'runtime_run_id', 'worker_id', 'lease_token', 'state', 'acquired_at', 'heartbeat_at', 'lease_expires_at', 'last_error', 'updated_at'],
        'resident_job_runs' => ['runtime_run_id', 'task_id', 'service_id', 'resident_run_id', 'lifecycle', 'dispatched_at', 'cancel_requested_at', 'unconfirmed_at', 'reconciled_at', 'updated_at'],
        'cluster_gpu_metric_snapshots' => ['id', 'station_id', 'sampled_at', 'gpu_json'],
        'cluster_photo_assets' => ['id', 'image_id', 'station_id', 'remote_image_id', 'owner_member_id', 'owner_token_id', 'expires_at', 'last_accessed_at', 'created_at'],
        'tasks' => ['owner_member_id', 'owner_token_id', 'requested_mode', 'pack_id', 'pack_version', 'job', 'job_contract_json', 'job_contract_digest', 'runtime_mode', 'accelerator', 'route_resolved_at', 'source_artifact_id', 'source_task_id', 'retry_of_task_id', 'callback_target_id', 'waiting_reason', 'next_attempt_at', 'waiting_detail_json', 'error_code', 'source_expires_at', 'workspace_expires_at', 'source_state', 'workspace_state', 'retention_state', 'purged_at', 'freed_bytes', 'purge_claim_token', 'purge_claimed_at', 'purge_error', 'metadata_purge_claim_token', 'metadata_purge_claimed_at', 'partial_purge_error', 'partial_purge_retry_at'],
        'voice_profiles' => ['source_task_id', 'reference_contract', 'transcript_validation_json'],
        'facebook_crawler_profiles' => ['id', 'profile_id', 'owner_member_id', 'node_name', 'display_name', 'state', 'last_verified_at', 'active_task_id', 'login_secret_hash', 'login_container_name', 'login_port', 'login_expires_at', 'deleted_at', 'created_at', 'updated_at'],
        'sam3_sources' => ['id', 'source_id', 'service_id', 'display_name', 'protocol', 'source_url', 'clip_seconds', 'monitor_enabled', 'monitor_interval_seconds', 'last_error_code', 'last_seen_at', 'enabled', 'created_by', 'created_at', 'updated_at'],
        'sam3_monitor_runs' => ['id', 'source_id', 'service_id', 'task_id', 'runtime_run_id', 'state', 'last_heartbeat_at', 'started_at', 'stopped_at', 'last_safe_error_code'],
        'sam3_monitor_event_artifacts' => ['id', 'runtime_run_id', 'sequence', 'artifact_id', 'created_at'],
        'task_artifacts' => ['artifact_type', 'sha256', 'metadata_json', 'expires_at', 'state', 'pinned_at', 'legal_hold', 'acknowledged_at', 'last_accessed_at', 'purged_at', 'purge_error', 'purge_claim_token', 'purge_claimed_at', 'download_claim_token', 'download_claim_expires_at'],
        'task_artifact_holds' => ['id', 'source_artifact_id', 'downstream_task_id', 'held_at', 'released_at'],
        'runtime_runs' => ['task_id', 'attempt_no', 'container_id', 'gpu_process_baseline_json', 'owned_gpu_pids_json'],
    ];
    $tables = array_fill_keys($db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN), true);
    $missing = [];

    foreach ($required as $table => $columns) {
        if (!isset($tables[$table])) {
            $missing[] = $table;
            continue;
        }
        $present = array_fill_keys(array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name'), true);
        foreach ($columns as $column) {
            if (!isset($present[$column])) {
                $missing[] = $table . '.' . $column;
            }
        }
        if ($table === 'runtime_resource_leases' && isset($present['resource_key'])) {
            $lease = $db->prepare('SELECT 1 FROM runtime_resource_leases WHERE resource_key = :resource_key');
            $lease->execute([':resource_key' => 'gpu:0']);
            if ($lease->fetchColumn() === false) {
                $missing[] = 'runtime_resource_leases.gpu:0';
            }
        }
    }

    return $missing;
}

function hub_add_column_if_missing(PDO $db, string $table, string $column, string $definition): void
{
    $columns = $db->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($columns as $existing) {
        if (($existing['name'] ?? '') === $column) {
            return;
        }
    }

    $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function hub_seed_admin_user(PDO $db): void
{
    $stmt = $db->prepare('SELECT id FROM users WHERE username = :username');
    $stmt->execute([':username' => 'admin']);
    $existing = $stmt->fetch();
    if ($existing) {
        $db->prepare(
            "UPDATE users
             SET role = 'system_admin', is_protected = 1, is_enabled = 1,
                 display_name = COALESCE(NULLIF(display_name, ''), username),
                 updated_at = :updated_at
             WHERE id = :id"
        )->execute([':updated_at' => hub_now(), ':id' => (int)$existing['id']]);
        return;
    }

    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO users
            (username, password_hash, must_change_password, role, display_name, is_protected, is_enabled, created_at, updated_at)
         VALUES
            (:username, :password_hash, 1, :role, :display_name, 1, 1, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':username' => 'admin',
        ':password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        ':role' => 'system_admin',
        ':display_name' => 'admin',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function hub_seed_hello_service(PDO $db): void
{
    hub_install_pack($db, 'hello', 'hello-main');
}
