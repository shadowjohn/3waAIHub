<?php
declare(strict_types=1);

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

function hub_migrate(PDO $db): void
{
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
    service_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    output TEXT NOT NULL,
    exit_code INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    action TEXT NOT NULL,
    details TEXT NOT NULL,
    created_at TEXT NOT NULL
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
    created_at TEXT NOT NULL,
    FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
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
SQL);

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
    hub_add_column_if_missing($db, 'command_jobs', 'stderr_path', 'TEXT NULL');
    hub_add_column_if_missing($db, 'command_jobs', 'progress', 'INTEGER NOT NULL DEFAULT 0');
    hub_add_column_if_missing($db, 'command_jobs', 'stage', 'TEXT NULL');
    hub_add_column_if_missing($db, 'command_jobs', 'current_message', 'TEXT NULL');
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
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_member_id ON api_access_logs(member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_access_logs_token_id ON api_access_logs(token_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_tokens_member_id ON api_tokens(member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_token_permissions_token_id ON api_token_service_permissions(token_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_token_permissions_mode ON api_token_service_permissions(mode)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_token_ip_rules_token_id ON api_token_ip_whitelists(token_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_token_usage_member_date ON api_token_usage_daily(member_id, usage_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_token_usage_token_date ON api_token_usage_daily(token_id, usage_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_service_settings_service_id ON service_settings(service_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_users_api_member_id ON users(api_member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_user_mode_permissions_user_id ON user_mode_permissions(user_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_user_mode_permissions_mode ON user_mode_permissions(mode)');
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
