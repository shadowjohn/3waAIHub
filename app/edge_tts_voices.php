<?php
declare(strict_types=1);

function hub_edge_tts_demo_failure(): never
{
    throw new RuntimeException('edge_tts_demo_initialization_failed');
}

function hub_edge_tts_voice_catalog(): array
{
    $path = HUB_ROOT . '/packs/edge-tts/service/voice_catalog.json';
    if (is_link($path) || !is_file($path)) {
        hub_edge_tts_demo_failure();
    }
    $json = file_get_contents($path);
    if (!is_string($json) || !hash_equals('2fd8ff8fdb16f5b767833187ed04bcd9d50bc91797168143af0ce9533f963ca5', hash('sha256', $json))) {
        hub_edge_tts_demo_failure();
    }
    try {
        $catalogue = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        hub_edge_tts_demo_failure();
    }
    if (!is_array($catalogue) || !array_is_list($catalogue) || count($catalogue) !== 14) {
        hub_edge_tts_demo_failure();
    }

    $ids = [];
    $files = [];
    foreach ($catalogue as $voice) {
        if (!is_array($voice) || array_keys($voice) !== ['id', 'display_name', 'locale', 'gender', 'memo', 'demo_text', 'demo_file']) {
            hub_edge_tts_demo_failure();
        }
        foreach ($voice as $value) {
            if (!is_string($value) || $value === '') {
                hub_edge_tts_demo_failure();
            }
        }
        if (preg_match('/^zh-[A-Za-z0-9-]+$/', $voice['id']) !== 1
            || !in_array($voice['gender'], ['male', 'female'], true)
            || preg_match('/^[0-9]{2}_[a-z0-9_]+\.mp3$/', $voice['demo_file']) !== 1
            || isset($ids[$voice['id']]) || isset($files[$voice['demo_file']])) {
            hub_edge_tts_demo_failure();
        }
        $ids[$voice['id']] = true;
        $files[$voice['demo_file']] = true;
    }

    return $catalogue;
}

function hub_edge_tts_demo_root(string $serviceKey): string
{
    if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $serviceKey) !== 1) {
        hub_edge_tts_demo_failure();
    }

    return HUB_DATA_DIR . '/results/edge-tts-demos/' . $serviceKey . '/current';
}

function hub_edge_tts_demo_directory_entries(string $dir, array $catalogue): array
{
    if (is_link($dir) || !is_dir($dir)) {
        hub_edge_tts_demo_failure();
    }
    $availabilityPath = $dir . '/available.json';
    $availabilityStat = @lstat($availabilityPath);
    if (is_link($availabilityPath) || !is_array($availabilityStat) || (($availabilityStat['mode'] & 0170000) !== 0100000)
        || (int)$availabilityStat['size'] < 1 || (int)$availabilityStat['size'] > 1024 * 1024) {
        hub_edge_tts_demo_failure();
    }
    try {
        $availability = json_decode((string)file_get_contents($availabilityPath), true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        hub_edge_tts_demo_failure();
    }
    if (!is_array($availability) || array_keys($availability) !== ['version', 'voices'] || ($availability['version'] ?? null) !== 1
        || !is_array($availability['voices'] ?? null) || !array_is_list($availability['voices'])) {
        hub_edge_tts_demo_failure();
    }

    $catalogueById = [];
    foreach ($catalogue as $voice) {
        $catalogueById[$voice['id']] = $voice;
    }
    $verified = [];
    $expectedFiles = ['available.json' => true];
    foreach ($availability['voices'] as $entry) {
        if (!is_array($entry) || array_keys($entry) !== ['id', 'file', 'bytes', 'sha256']
            || !is_string($entry['id'] ?? null) || !is_string($entry['file'] ?? null) || !is_int($entry['bytes'] ?? null)
            || !is_string($entry['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $entry['sha256']) !== 1
            || !isset($catalogueById[$entry['id']]) || isset($verified[$entry['id']])) {
            hub_edge_tts_demo_failure();
        }
        $voice = $catalogueById[$entry['id']];
        if ($entry['file'] !== $voice['demo_file'] || $entry['bytes'] < 1 || $entry['bytes'] > 1024 * 1024 || isset($expectedFiles[$entry['file']])) {
            hub_edge_tts_demo_failure();
        }
        $path = $dir . '/' . $voice['demo_file'];
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $size = @filesize($path);
        if (is_link($path) || !is_array($stat) || (($stat['mode'] & 0170000) !== 0100000)
            || !is_int($size) || $size !== $entry['bytes'] || hub_artifact_safe_path($path) === null
            || !hash_equals($entry['sha256'], (string)hash_file('sha256', $path))) {
            hub_edge_tts_demo_failure();
        }
        $verified[$entry['id']] = $entry;
        $expectedFiles[$entry['file']] = true;
    }
    if ($verified === []) {
        hub_edge_tts_demo_failure();
    }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name !== '.' && $name !== '..' && !isset($expectedFiles[$name])) {
            hub_edge_tts_demo_failure();
        }
    }

    return array_values($verified);
}

function hub_edge_tts_cleanup_demo_directory(string $dir, string $parent): void
{
    $parentReal = realpath($parent);
    $dirReal = realpath($dir);
    if ($parentReal === false || $dirReal === false || is_link($dir) || dirname($dirReal) !== $parentReal
        || preg_match('/^\.(?:staging|backup)-[a-f0-9]{32}$/', basename($dirReal)) !== 1) {
        return;
    }
    foreach (scandir($dirReal) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dirReal . '/' . $name;
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($dirReal);
}

function hub_edge_tts_initialize_voice_demos(array $pack, string $serviceKey, ?callable $runner = null): array
{
    if ($runner === null && defined('HUB_TESTING') && HUB_TESTING === true) {
        return ['test_internal_skipped' => true];
    }
    $staging = null;
    $parent = null;
    try {
        $catalogue = hub_edge_tts_voice_catalog();
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $image = $manifest['runner_build']['image'] ?? null;
        if (($manifest['id'] ?? null) !== 'edge-tts' || $image !== '3waaihub/edge-tts:0.2.0') {
            hub_edge_tts_demo_failure();
        }
        $current = hub_edge_tts_demo_root($serviceKey);
        $parent = dirname($current);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            hub_edge_tts_demo_failure();
        }
        $parentReal = realpath($parent);
        if ($parentReal === false || is_link($parent) || str_replace('\\', '/', $parentReal) !== rtrim(str_replace('\\', '/', $parent), '/')) {
            hub_edge_tts_demo_failure();
        }
        $staging = $parentReal . '/.staging-' . bin2hex(random_bytes(16));
        if (!mkdir($staging, 0700)) {
            hub_edge_tts_demo_failure();
        }
        $containerName = 'edge-tts-demo-' . substr($serviceKey, 0, 100) . '-' . bin2hex(random_bytes(16));
        $command = [
            'docker', 'run', '--pull=never', '--network', 'bridge', '--cap-add', 'NET_ADMIN',
            '--mount', 'type=bind,src=' . $staging . ',dst=/workspace/output',
            '--name', $containerName, '--entrypoint', '/app/edge-tts-entrypoint.sh',
            $image, '/app/generate_demos.py',
        ];
        $runner ??= 'hub_run_linux_docker_command';
        try {
            $result = $runner($command, 300);
        } catch (Throwable) {
            hub_edge_tts_demo_failure();
        } finally {
            hub_pack_job_default_container_cleanup($runner, $containerName, 300);
        }
        if (!is_array($result ?? null) || (int)($result['exit_code'] ?? 1) !== 0) {
            hub_edge_tts_demo_failure();
        }
        $verified = hub_edge_tts_demo_directory_entries($staging, $catalogue);
        if (!chmod($staging, 0755)) {
            hub_edge_tts_demo_failure();
        }
        $backup = $parentReal . '/.backup-' . bin2hex(random_bytes(16));
        if (file_exists($current) || is_link($current)) {
            if (is_link($current) || !is_dir($current) || !rename($current, $backup)) {
                hub_edge_tts_demo_failure();
            }
        }
        if (!rename($staging, $current)) {
            if (is_dir($backup)) {
                @rename($backup, $current);
            }
            hub_edge_tts_demo_failure();
        }
        $staging = null;
        hub_edge_tts_cleanup_demo_directory($backup, $parentReal);

        return ['succeeded' => count($verified), 'failed' => count($catalogue) - count($verified)];
    } catch (Throwable) {
        if (is_string($staging) && is_string($parent)) {
            hub_edge_tts_cleanup_demo_directory($staging, $parent);
        }
        hub_edge_tts_demo_failure();
    }
}

function hub_edge_tts_verified_voices(string $serviceKey): array
{
    try {
        return hub_edge_tts_demo_directory_entries(hub_edge_tts_demo_root($serviceKey), hub_edge_tts_voice_catalog());
    } catch (Throwable) {
        return [];
    }
}
