<?php
declare(strict_types=1);

function hub_validate_service_port(int $port): bool
{
    return $port >= 18100 && $port <= 18999;
}

function hub_allocate_local_port(PDO $db): int
{
    $used = $db->query('SELECT local_port FROM services WHERE local_port IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    $used = array_map('intval', $used);

    for ($port = 18100; $port <= 18999; $port++) {
        if (!in_array($port, $used, true) && !hub_port_is_busy($port)) {
            return $port;
        }
    }

    throw new RuntimeException('No available local port in 18100-18999.');
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
