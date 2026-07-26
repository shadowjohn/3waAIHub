<?php
declare(strict_types=1);

hub_test('cluster admin keeps saved roles visible when its local HTTP host cannot form a pairing URL', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/cluster.php');

    hub_test_assert(
        str_contains($page, '無法依目前的主機網址產生配對連結'),
        'cluster admin must show a pairing URL remediation instead of rendering a 500 page'
    );
});
