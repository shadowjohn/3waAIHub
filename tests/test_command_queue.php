<?php
declare(strict_types=1);

hub_test('command worker allowlist includes Docker builder prune only as explicit maintenance action', function (): void {
    hub_test_assert(hub_is_valid_job_action('docker_builder_prune'), 'docker_builder_prune must be allowlisted');
    hub_test_assert(!hub_is_valid_job_action('docker system prune -af'), 'raw Docker commands must stay rejected');
});
