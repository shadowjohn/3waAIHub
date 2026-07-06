<?php
declare(strict_types=1);

function hub_validate_service_port(int $port, ?PDO $db = null): bool
{
    [$start, $end] = hub_docker_port_range($db);
    return $port >= $start && $port <= $end;
}

function hub_allocate_local_port(PDO $db): int
{
    $used = $db->query('SELECT local_port FROM services WHERE local_port IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    $used = array_map('intval', $used);
    [$start, $end] = hub_docker_port_range($db);

    for ($port = $start; $port <= $end; $port++) {
        if (!in_array($port, $used, true) && !hub_port_is_busy($port)) {
            return $port;
        }
    }

    throw new RuntimeException('No available local port in ' . $start . '-' . $end . '.');
}

function hub_docker_port_range(?PDO $db = null): array
{
    if ($db) {
        $start = (int)hub_get_storage_setting($db, 'AIHUB_DOCKER_PORT_START');
        $end = (int)hub_get_storage_setting($db, 'AIHUB_DOCKER_PORT_END');
        if ($start >= 1024 && $end <= 65535 && $start < $end) {
            return [$start, $end];
        }
    }

    return [18100, 18999];
}

function hub_port_is_busy(int $port): bool
{
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($socket === false) {
        return false;
    }

    fclose($socket);
    return true;
}
