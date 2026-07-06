<?php
declare(strict_types=1);

hub_test('benchmark skeleton records pack catalog scan', function (): void {
    $db = hub_test_reset_db();
    $result = hub_run_benchmark_case($db, 'pack_catalog_scan');
    hub_test_assert($result['status'] === 'pass', 'pack_catalog_scan did not pass');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM benchmark_runs')->fetchColumn() === 1, 'benchmark run was not recorded');
});
