<?php
declare(strict_types=1);

hub_test('Windows Core models root option is persisted in Hub Settings', function (): void {
    if (hub_platform_id() !== 'windows') {
        hub_test_skip('Windows Core installer integration.');
    }

    $dbPath = sys_get_temp_dir() . '/3waaihub_windows_models_' . bin2hex(random_bytes(4)) . '.sqlite';
    $modelsRoot = 'D:\\DATA\\3waAIHub-models-' . bin2hex(random_bytes(4));
    $db = null;

    try {
        $result = hub_run_command(
            [PHP_BINARY, HUB_ROOT . '/scripts/init_db.php', '--models-root=' . $modelsRoot],
            30,
            ['AIHUB_TEST_DB' => $dbPath]
        );
        hub_test_assert($result['exit_code'] === 0, 'Core DB initialization failed: ' . $result['output']);

        $db = new PDO('sqlite:' . $dbPath);
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute([':key' => 'AIHUB_MODELS_DIR']);
        hub_test_assert($stmt->fetchColumn() === $modelsRoot, 'Core ModelsRoot must be persisted in Hub Settings');
    } finally {
        $db = null;
        foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
});
