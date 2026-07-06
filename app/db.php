<?php
declare(strict_types=1);

function hub_db(): PDO
{
    hub_ensure_runtime_dirs();
    $db = new PDO('sqlite:' . HUB_DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
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
SQL);

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
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_services_service_key ON services(service_key) WHERE service_key IS NOT NULL');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_services_local_port ON services(local_port) WHERE local_port IS NOT NULL');
    hub_add_column_if_missing($db, 'command_jobs', 'stderr_path', 'TEXT NULL');
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
    if ($stmt->fetch()) {
        return;
    }

    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO users (username, password_hash, must_change_password, created_at, updated_at)
         VALUES (:username, :password_hash, 1, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':username' => 'admin',
        ':password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function hub_seed_hello_service(PDO $db): void
{
    hub_install_pack($db, 'hello', 'hello-main');
}
