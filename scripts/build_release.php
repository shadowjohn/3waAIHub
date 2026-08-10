<?php
declare(strict_types=1);

/**
 * 將 checkout 組裝成可部署 artifact；Web Server 僅可指向 dist/public。
 * 測試、文件、資料與 Pack acceptance 不會混入正式 deploy tree。
 */

function hub_release_build_usage(): string
{
    return 'Usage: php scripts/build_release.php [--output=<directory>] [--check]' . PHP_EOL;
}

function hub_release_build_normalize_path(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function hub_release_build_remove_tree(string $directory): void
{
    if (is_link($directory)) {
        throw new RuntimeException('Release output must not be a symlink.');
    }
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        $path = $entry->getPathname();
        if ($entry->isLink()) {
            throw new RuntimeException('Release output must not contain symlinks.');
        }
        if ($entry->isDir()) {
            hub_release_build_remove_tree($path);
            if (!rmdir($path)) {
                throw new RuntimeException('Cannot remove release directory: ' . $path);
            }
            continue;
        }
        if (!$entry->isFile() || !unlink($path)) {
            throw new RuntimeException('Cannot remove release file: ' . $path);
        }
    }
}

function hub_release_build_copy_file(string $source, string $destination, ?callable $transform = null): void
{
    if (is_link($source) || !is_file($source)) {
        throw new RuntimeException('Release source must be a regular file: ' . $source);
    }
    $parent = dirname($destination);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException('Cannot create release directory: ' . $parent);
    }
    if ($transform !== null) {
        $content = file_get_contents($source);
        if ($content === false) {
            throw new RuntimeException('Cannot read release source: ' . $source);
        }
        $content = $transform($content);
        if (!is_string($content) || file_put_contents($destination, $content, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write release file: ' . $destination);
        }
    } elseif (!copy($source, $destination)) {
        throw new RuntimeException('Cannot copy release file: ' . $source);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        @chmod($destination, fileperms($source) & 0777);
    }
}

/**
 * @param callable(string, bool): bool $include
 * @param callable(string, string): string|null $transform
 */
function hub_release_build_copy_tree(string $source, string $destination, callable $include, ?callable $transform = null, string $relative = ''): void
{
    if (is_link($source) || !is_dir($source)) {
        throw new RuntimeException('Release source must be a regular directory: ' . $source);
    }
    if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
        throw new RuntimeException('Cannot create release directory: ' . $destination);
    }
    foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry) {
        $name = $entry->getFilename();
        $entryRelative = $relative === '' ? $name : $relative . '/' . $name;
        $entryPath = $entry->getPathname();
        if ($entry->isLink()) {
            throw new RuntimeException('Release source must not contain symlinks: ' . $entryPath);
        }
        if (!$include($entryRelative, $entry->isDir())) {
            continue;
        }
        $targetPath = $destination . DIRECTORY_SEPARATOR . $name;
        if ($entry->isDir()) {
            hub_release_build_copy_tree($entryPath, $targetPath, $include, $transform, $entryRelative);
            continue;
        }
        if (!$entry->isFile()) {
            throw new RuntimeException('Release source must be a regular file: ' . $entryPath);
        }
        hub_release_build_copy_file(
            $entryPath,
            $targetPath,
            $transform === null ? null : static fn (string $content): string => $transform($content, $entryRelative),
        );
    }
}

function hub_release_build_include_pack(string $relative, bool $isDirectory): bool
{
    $parts = explode('/', str_replace('\\', '/', $relative));
    foreach ($parts as $part) {
        if (in_array(strtolower($part), ['.git', '.github', 'acceptance', 'tests', 'test', 'node_modules', 'vendor', '.venv', '__pycache__'], true)) {
            return false;
        }
    }
    if ($isDirectory) {
        return true;
    }
    $name = strtolower((string)end($parts));
    return $name !== 'readme.md'
        && !str_starts_with($name, 'test_')
        && !str_ends_with($name, '_test.py')
        && !str_ends_with($name, '.fpr');
}

function hub_release_build_include_runtime_script(string $relative, bool $isDirectory): bool
{
    if ($isDirectory || str_starts_with($relative, 'windows/') || str_starts_with($relative, 'wsl/')) {
        return true;
    }
    return !in_array(strtolower(basename($relative)), [
        'agent_manifest_smoke.php', 'api_smoke_client.php', 'audio_packs_acceptance.php', 'benchmark.php',
        'bootstrap_self_check.sh', 'build_release.php', 'docparser_acceptance.php', 'edge_tts_acceptance.php',
        'facebook_crawler_smoke.php', 'fortify_sast.ps1', 'run_tests.php', 'self_check.php',
        'token_api_smoke.php', 'voxcpm2_cluster_acceptance.php',
    ], true);
}

function hub_release_build_rewrite_public_php(string $content, string $relative): string
{
    if (str_starts_with($relative, 'admin/') || str_starts_with($relative, 'catalog_show/')) {
        $content = preg_replace(
            "#(require(?:_once)?\\s+)__DIR__\\s*\\.\\s*'/\\.\\./app/bootstrap\\.php'#",
            "$1dirname(__DIR__) . '/_bootstrap.php'",
            $content,
        ) ?? $content;
        return preg_replace("#__DIR__\\s*\\.\\s*'/\\.\\./app/#", "dirname(__DIR__, 2) . '/app/", $content) ?? $content;
    }
    return preg_replace(
        "#(require(?:_once)?\\s+)__DIR__\\s*\\.\\s*'/app/bootstrap\\.php'#",
        "$1__DIR__ . '/_bootstrap.php'",
        $content,
    ) ?? $content;
}

function hub_release_build_manifest(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || !$entry->isFile()) {
            throw new RuntimeException('Release output must contain regular files only.');
        }
        $path = $entry->getPathname();
        $relative = str_replace('\\', '/', substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
        if ($relative === 'release-manifest.json') {
            continue;
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Cannot hash release file: ' . $relative);
        }
        $files[$relative] = $hash;
    }
    ksort($files, SORT_STRING);
    return ['schema_version' => 1, 'public_root' => 'public', 'files' => $files];
}

function hub_release_build_verify_manifest(string $root): void
{
    $manifestPath = $root . '/release-manifest.json';
    $manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
    if (!is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1 || ($manifest['public_root'] ?? null) !== 'public' || !is_array($manifest['files'] ?? null)) {
        throw new RuntimeException('Release manifest is invalid.');
    }
    if (!is_dir($root . '/public') || is_dir($root . '/public/app') || is_dir($root . '/public/packs') || is_dir($root . '/public/scripts')) {
        throw new RuntimeException('Release public root contains private source.');
    }
    foreach (['data', 'docs', 'fortify', 'tests', 'tools'] as $forbiddenDirectory) {
        if (file_exists($root . '/' . $forbiddenDirectory) || is_link($root . '/' . $forbiddenDirectory)) {
            throw new RuntimeException('Release artifact contains a non-deployable directory: ' . $forbiddenDirectory);
        }
    }
    foreach ($manifest['files'] as $relative => $expectedHash) {
        if (!is_string($relative) || !is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            throw new RuntimeException('Release manifest contains an invalid file hash.');
        }
        $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $expectedHash) {
            throw new RuntimeException('Release manifest verification failed: ' . $relative);
        }
    }
    if (hub_release_build_manifest($root)['files'] !== $manifest['files']) {
        throw new RuntimeException('Release manifest does not describe the complete artifact.');
    }
}

$sourceRoot = dirname(__DIR__);
$output = $sourceRoot . '/dist';
$checkOnly = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
        continue;
    }
    if ($argument === '--check') {
        $checkOnly = true;
        continue;
    }
    if ($argument === '--help' || $argument === '-h') {
        echo hub_release_build_usage();
        exit(0);
    }
    fwrite(STDERR, hub_release_build_usage());
    exit(2);
}

try {
    $output = hub_release_build_normalize_path($output);
    if ($output === '' || !is_dir(dirname($output))) {
        throw new InvalidArgumentException('Release output parent directory is unavailable.');
    }
    $sourceRootReal = realpath($sourceRoot);
    $outputParentReal = realpath(dirname($output));
    if ($sourceRootReal === false || $outputParentReal === false) {
        throw new RuntimeException('Cannot resolve release source or output parent.');
    }
    $resolvedOutput = hub_release_build_normalize_path($outputParentReal . '/' . basename($output));
    if (strcasecmp($resolvedOutput, hub_release_build_normalize_path($sourceRootReal)) === 0 || basename($resolvedOutput) === '') {
        throw new InvalidArgumentException('Release output cannot be the source root.');
    }
    if ($checkOnly) {
        hub_release_build_verify_manifest($resolvedOutput);
        echo 'release=valid public_root=' . $resolvedOutput . '/public' . PHP_EOL;
        exit(0);
    }

    $stage = $resolvedOutput . '.building-' . bin2hex(random_bytes(8));
    if (file_exists($stage) || is_link($stage) || !mkdir($stage, 0775, true)) {
        throw new RuntimeException('Cannot create release staging directory.');
    }
    try {
        $copyAll = static fn (string $relative, bool $isDirectory): bool => true;
        $publicRoot = $stage . '/public';
        foreach (glob($sourceRootReal . '/*.php') ?: [] as $file) {
            hub_release_build_copy_file($file, $publicRoot . '/' . basename($file), static fn (string $content): string => hub_release_build_rewrite_public_php($content, basename($file)));
        }
        foreach (['admin', 'assets', 'catalog_show'] as $directory) {
            hub_release_build_copy_tree(
                $sourceRootReal . '/' . $directory,
                $publicRoot . '/' . $directory,
                $copyAll,
                static fn (string $content, string $relative): string => str_ends_with($relative, '.php') ? hub_release_build_rewrite_public_php($content, $directory . '/' . $relative) : $content,
            );
        }
        foreach (['web.config', '.htaccess'] as $file) {
            hub_release_build_copy_file($sourceRootReal . '/' . $file, $publicRoot . '/' . $file);
        }
        file_put_contents($publicRoot . '/_bootstrap.php', "<?php\ndeclare(strict_types=1);\n\nrequire dirname(__DIR__) . '/app/bootstrap.php';\n", LOCK_EX);

        foreach (['app', 'bin', 'i18n', 'crontab'] as $directory) {
            hub_release_build_copy_tree($sourceRootReal . '/' . $directory, $stage . '/' . $directory, $copyAll);
        }
        hub_release_build_copy_tree($sourceRootReal . '/packs', $stage . '/packs', 'hub_release_build_include_pack');
        hub_release_build_copy_tree($sourceRootReal . '/scripts', $stage . '/scripts', 'hub_release_build_include_runtime_script');
        foreach (['install.ps1', 'install.sh', '3waAIHub_Crontab.xml', 'run_server.bat'] as $file) {
            hub_release_build_copy_file($sourceRootReal . '/' . $file, $stage . '/' . $file);
        }
        hub_release_build_copy_file($sourceRootReal . '/runtime-settings.example.conf', $stage . '/config/runtime-settings.example.conf');

        $manifest = hub_release_build_manifest($stage);
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($manifestJson) || file_put_contents($stage . '/release-manifest.json', $manifestJson . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write release manifest.');
        }
        hub_release_build_verify_manifest($stage);
        if (file_exists($resolvedOutput) || is_link($resolvedOutput)) {
            hub_release_build_remove_tree($resolvedOutput);
            if (!rmdir($resolvedOutput)) {
                throw new RuntimeException('Cannot replace existing release output.');
            }
        }
        if (!rename($stage, $resolvedOutput)) {
            $lastError = error_get_last();
            throw new RuntimeException('Cannot finalize release output: ' . (string)($lastError['message'] ?? 'unknown filesystem error'));
        }
        echo 'release=created public_root=' . $resolvedOutput . '/public files=' . count($manifest['files']) . PHP_EOL;
    } catch (Throwable $e) {
        if (is_dir($stage) && !is_link($stage)) {
            hub_release_build_remove_tree($stage);
            @rmdir($stage);
        }
        throw $e;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Release build failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
