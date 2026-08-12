<?php
declare(strict_types=1);

function hub_test_release_artifact_remove_tree(string $path): void
{
    if (is_link($path)) {
        throw new RuntimeException('Release artifact test directory must not be a symlink.');
    }
    if (!is_dir($path)) {
        return;
    }

    $tempRoot = realpath(sys_get_temp_dir());
    $resolved = realpath($path);
    if ($tempRoot === false || $resolved === false || dirname($resolved) !== $tempRoot || !str_starts_with(basename($resolved), '3waaihub_release_artifact_')) {
        throw new RuntimeException('Release artifact test cleanup target is invalid.');
    }

    $removeContents = static function (string $directory) use (&$removeContents): void {
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            $entryPath = $entry->getPathname();
            if ($entry->isLink()) {
                throw new RuntimeException('Release artifact test output must not contain symlinks.');
            }
            if ($entry->isDir()) {
                $removeContents($entryPath);
                if (!rmdir($entryPath)) {
                    throw new RuntimeException('Cannot remove release artifact test directory.');
                }
                continue;
            }
            if (!$entry->isFile() || !unlink($entryPath)) {
                throw new RuntimeException('Cannot remove release artifact test file.');
            }
        }
    };
    $removeContents($resolved);
    if (!rmdir($resolved)) {
        throw new RuntimeException('Cannot remove release artifact test directory.');
    }
}

hub_test('release builder creates a hash-verified private deployment artifact', function (): void {
    $releaseRoot = sys_get_temp_dir() . '/3waaihub_release_artifact_' . bin2hex(random_bytes(12));
    $output = $releaseRoot . '/dist';
    $runtimeDataRoot = $releaseRoot . '/data';

    try {
        hub_test_assert(mkdir($runtimeDataRoot, 0775, true), 'cannot create external release runtime data directory');
        $result = hub_run_command([PHP_BINARY, HUB_ROOT . '/scripts/build_release.php', '--output=' . $output], 120);
        hub_test_assert((int)$result['exit_code'] === 0, 'release build failed: ' . trim((string)$result['stderr']));

        $manifestPath = $output . '/release-manifest.json';
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        hub_test_assert(is_array($manifest), 'release manifest must be valid JSON');
        hub_test_assert(($manifest['schema_version'] ?? null) === 1, 'release manifest schema changed');
        hub_test_assert(($manifest['public_root'] ?? null) === 'public', 'release manifest must declare public root');
        hub_test_assert(is_array($manifest['files'] ?? null) && $manifest['files'] !== [], 'release manifest must contain files');

        $files = $manifest['files'];
        foreach (['public/index.php', 'public/login.php', 'public/admin/index.php', 'app/bootstrap.php', 'packs/catalog.json'] as $required) {
            hub_test_assert(isset($files[$required]), 'release artifact is missing required file: ' . $required);
        }
        hub_test_assert(!isset($files['scripts/build_release.php']), 'release builder must stay in the source checkout');
        foreach (['public/app', 'public/packs', 'public/scripts', 'data', 'tests', 'docs', 'fortify', 'tools'] as $forbidden) {
            hub_test_assert(!file_exists($output . '/' . $forbidden), 'release artifact must not contain: ' . $forbidden);
        }
        foreach (array_keys($files) as $relativePath) {
            $isPublicVendorAsset = str_starts_with($relativePath, 'public/assets/js/vendor/');
            hub_test_assert(
                $isPublicVendorAsset || preg_match('#(?:^|/)(?:\.git|\.github|data|docs|fortify|tests|tools|node_modules|vendor|\.venv|__pycache__)(?:/|$)#', $relativePath) !== 1,
                'release manifest contains non-deployable path: ' . $relativePath,
            );
            hub_test_assert(
                preg_match('#(?:^|/)(?:acceptance|test_[^/]+|[^/]+_test\.py)(?:/|$)#', $relativePath) !== 1,
                'release manifest contains Pack acceptance or test source: ' . $relativePath,
            );
            $expectedHash = (string)$files[$relativePath];
            hub_test_assert(preg_match('/^[a-f0-9]{64}$/', $expectedHash) === 1, 'release manifest hash is invalid: ' . $relativePath);
            hub_test_assert(hash_file('sha256', $output . '/' . $relativePath) === $expectedHash, 'release manifest hash mismatch: ' . $relativePath);
        }

        $bootstrap = (string)file_get_contents($output . '/public/_bootstrap.php');
        hub_test_assert(str_contains($bootstrap, "dirname(__DIR__) . '/app/bootstrap.php'"), 'public bootstrap must load private app code');
        $login = (string)file_get_contents($output . '/public/login.php');
        hub_test_assert(str_contains($login, "__DIR__ . '/_bootstrap.php'"), 'public entrypoint must use private bootstrap bridge');
        $publicApiDocs = (string)file_get_contents($output . '/public/public_api_docs.php');
        hub_test_assert(str_contains($publicApiDocs, "dirname(__DIR__) . '/app/public_api_docs.php'"), 'public API docs must load private helper code outside document root');
        foreach (glob($output . '/public/*.php') ?: [] as $entrypoint) {
            $entrypointSource = (string)file_get_contents($entrypoint);
            hub_test_assert(preg_match("#(?:require(?:_once)?\\s+)__DIR__\\s*\\.\\s*'/app/#", $entrypointSource) !== 1, 'public entrypoint must not load app code from document root: ' . basename($entrypoint));
        }

        $dataProbe = hub_run_command([
            PHP_BINARY,
            '-r',
            'require ' . var_export($output . '/public/_bootstrap.php', true) . '; echo HUB_DATA_DIR;',
        ], 60, [
            'AIHUB_TEST_DB' => '',
            'AIHUB_TEST_DATA_DIR' => '',
        ]);
        hub_test_assert((int)$dataProbe['exit_code'] === 0, 'release data-root probe failed: ' . trim((string)$dataProbe['stderr']));
        hub_test_assert(
            hub_storage_paths_equal(trim((string)$dataProbe['stdout']), $runtimeDataRoot),
            'release bootstrap must use the external sibling data directory',
        );
        hub_test_assert(!file_exists($output . '/data'), 'release bootstrap must not create runtime data inside the artifact');

        hub_test_assert(file_put_contents($output . '/unexpected.txt', 'not-manifested', LOCK_EX) !== false, 'cannot create release tamper fixture');
        $tamperCheck = hub_run_command([PHP_BINARY, HUB_ROOT . '/scripts/build_release.php', '--output=' . $output, '--check'], 60);
        hub_test_assert((int)$tamperCheck['exit_code'] !== 0, 'release check must reject files missing from the manifest');
        hub_test_assert(unlink($output . '/unexpected.txt'), 'cannot remove release tamper fixture');
        hub_test_assert(mkdir($output . '/data', 0775), 'cannot create release data-directory fixture');
        $directoryCheck = hub_run_command([PHP_BINARY, HUB_ROOT . '/scripts/build_release.php', '--output=' . $output, '--check'], 60);
        hub_test_assert((int)$directoryCheck['exit_code'] !== 0, 'release check must reject a runtime data directory');
    } finally {
        hub_test_release_artifact_remove_tree($releaseRoot);
    }
});
