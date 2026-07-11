<?php
declare(strict_types=1);

function hub_list_services(PDO $db): array
{
    return $db->query('SELECT * FROM services ORDER BY id')->fetchAll();
}

function hub_get_service(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM services WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $service = $stmt->fetch();

    return $service ?: null;
}

function hub_get_service_by_mode(PDO $db, string $mode): ?array
{
    $stmt = $db->prepare('SELECT * FROM services WHERE mode = :mode');
    $stmt->execute([':mode' => $mode]);
    $service = $stmt->fetch();

    return $service ?: null;
}

function hub_get_service_by_key(PDO $db, string $serviceKey): ?array
{
    $stmt = $db->prepare('SELECT * FROM services WHERE service_key = :service_key');
    $stmt->execute([':service_key' => $serviceKey]);
    $service = $stmt->fetch();

    return $service ?: null;
}

function hub_service_is_internal_task(array $service): bool
{
    return str_starts_with((string)($service['internal_url'] ?? ''), 'internal-task:');
}

function hub_set_service_enabled(PDO $db, string $mode, bool $enabled): void
{
    $stmt = $db->prepare('UPDATE services SET enabled = :enabled, updated_at = :updated_at WHERE mode = :mode');
    $stmt->execute([
        ':enabled' => $enabled ? 1 : 0,
        ':updated_at' => hub_now(),
        ':mode' => $mode,
    ]);
}

function hub_update_service_status(PDO $db, int $id, string $status): void
{
    $stmt = $db->prepare('UPDATE services SET status = :status, runtime_status = :status, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':updated_at' => hub_now(),
        ':id' => $id,
    ]);
}

function hub_update_service_port(PDO $db, int $id, int $port): void
{
    $stmt = $db->prepare('UPDATE services SET local_port = :local_port, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':local_port' => $port,
        ':updated_at' => hub_now(),
        ':id' => $id,
    ]);
}

function hub_add_service_log(PDO $db, int $serviceId, string $action, string $output, int $exitCode): void
{
    $stmt = $db->prepare(
        'INSERT INTO service_logs (service_id, action, output, exit_code, created_at)
         VALUES (:service_id, :action, :output, :exit_code, :created_at)'
    );
    $stmt->execute([
        ':service_id' => $serviceId,
        ':action' => $action,
        ':output' => $output,
        ':exit_code' => $exitCode,
        ':created_at' => hub_now(),
    ]);
}

function hub_list_service_logs(PDO $db, int $serviceId, int $limit = 100): array
{
    $stmt = $db->prepare(
        'SELECT * FROM service_logs WHERE service_id = :service_id ORDER BY id DESC LIMIT :limit'
    );
    $stmt->bindValue(':service_id', $serviceId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
