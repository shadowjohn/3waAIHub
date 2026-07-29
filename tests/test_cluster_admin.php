<?php
declare(strict_types=1);

hub_test('cluster admin keeps saved roles visible when its local HTTP host cannot form a pairing URL', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/cluster.php');

    hub_test_assert(
        str_contains($page, '無法依目前的主機網址產生配對連結'),
        'cluster admin must show a pairing URL remediation instead of rendering a 500 page'
    );
});

hub_test('cluster admin labels aggregate role with read-only child and mode counts', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/cluster.php');

    foreach (['聚合站台', 'count($stationRows)', 'count($selectedModes)', '個子節點', '個已發佈 Mode'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'cluster aggregate summary missing ' . $needle);
    }
});
