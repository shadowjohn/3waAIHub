<?php
declare(strict_types=1);

function hub_test_branding_upload(string $source, string $name = 'logo.png', ?int $reportedSize = null): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'brand_upload_');
    if ($tmp === false || !copy($source, $tmp)) {
        throw new RuntimeException('Cannot prepare branding test upload.');
    }

    return [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'size' => $reportedSize ?? (int)filesize($tmp),
        'name' => $name,
    ];
}

function hub_test_branding_error(PDO $db, array $upload): string
{
    try {
        hub_branding_store_logo($db, $upload);
    } catch (RuntimeException $error) {
        return $error->getMessage();
    } finally {
        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName !== '' && is_file($tmpName) && !is_link($tmpName)) {
            unlink($tmpName);
        }
    }

    throw new RuntimeException('Branding upload unexpectedly succeeded.');
}

hub_test('branding accepts validated raster images and stores only a managed basename', function (): void {
    $db = hub_test_reset_db();
    $upload = hub_test_branding_upload(HUB_ROOT . '/packs/image-birefnet/demo/acceptance/person_hair.png');

    try {
        $stored = hub_branding_store_logo($db, $upload);
        $basename = hub_get_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE');

        hub_test_assert($stored['mime'] === 'image/png', 'branding PNG MIME mismatch');
        hub_test_assert(is_file($stored['path']), 'managed branding file missing');
        hub_test_assert(
            preg_match('/^logo-[a-f0-9]{32}\.png$/', $basename) === 1 && basename($stored['path']) === $basename,
            'branding setting must contain only a random managed basename'
        );
        hub_test_assert(dirname((string)realpath($stored['path'])) === (string)realpath(hub_branding_root()), 'branding asset escaped managed root');
        hub_test_assert(hub_branding_active_asset($db)['path'] === $stored['path'], 'active branding asset mismatch');
    } finally {
        if (is_file($upload['tmp_name'])) {
            unlink($upload['tmp_name']);
        }
        hub_branding_restore_default($db);
    }
});

hub_test('branding accepts JPEG and WebP by inspected MIME', function (): void {
    $db = hub_test_reset_db();
    $webp = tempnam(sys_get_temp_dir(), 'brand_webp_');
    hub_test_assert($webp !== false, 'cannot create WebP fixture');
    file_put_contents($webp, base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v02aAA=', true));

    try {
        foreach ([
            [HUB_ROOT . '/packs/yolo/demo/sample.jpg', 'logo.bin', 'image/jpeg', 'jpg'],
            [$webp, 'logo.bin', 'image/webp', 'webp'],
        ] as [$source, $name, $mime, $extension]) {
            $upload = hub_test_branding_upload($source, $name);
            try {
                $stored = hub_branding_store_logo($db, $upload);
                hub_test_assert($stored['mime'] === $mime, 'branding MIME mismatch for ' . $mime);
                hub_test_assert(str_ends_with($stored['path'], '.' . $extension), 'branding extension mismatch for ' . $mime);
            } finally {
                if (is_file($upload['tmp_name'])) {
                    unlink($upload['tmp_name']);
                }
            }
        }
    } finally {
        hub_branding_restore_default($db);
        unlink($webp);
    }
});

hub_test('branding rejects SVG spoofed MIME oversized files and invalid dimensions', function (): void {
    $db = hub_test_reset_db();

    $svg = tempnam(sys_get_temp_dir(), 'brand_svg_');
    hub_test_assert($svg !== false, 'cannot create SVG fixture');
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    hub_test_assert(
        hub_test_branding_error($db, [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $svg,
            'size' => filesize($svg),
            'name' => 'logo.png',
        ]) === 'branding_unsupported_media_type',
        'SVG spoof rejection mismatch'
    );

    $oversized = tempnam(sys_get_temp_dir(), 'brand_large_');
    hub_test_assert($oversized !== false, 'cannot create oversized fixture');
    $handle = fopen($oversized, 'wb');
    hub_test_assert(is_resource($handle), 'cannot open oversized fixture');
    fseek($handle, 2 * 1024 * 1024);
    fwrite($handle, 'x');
    fclose($handle);
    hub_test_assert(
        hub_test_branding_error($db, [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $oversized,
            'size' => 1,
            'name' => 'logo.png',
        ]) === 'branding_payload_too_large',
        'actual branding byte limit must not trust reported size'
    );

    $wide = tempnam(sys_get_temp_dir(), 'brand_wide_');
    hub_test_assert($wide !== false, 'cannot create wide fixture');
    $image = imagecreatetruecolor(2049, 1);
    hub_test_assert($image !== false && imagepng($image, $wide), 'cannot write wide fixture');
    imagedestroy($image);
    hub_test_assert(
        hub_test_branding_error($db, [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $wide,
            'size' => filesize($wide),
            'name' => 'logo.png',
        ]) === 'branding_dimensions_too_large',
        'branding dimensions rejection mismatch'
    );

    $invalid = tempnam(sys_get_temp_dir(), 'brand_invalid_');
    hub_test_assert($invalid !== false, 'cannot create invalid fixture');
    $ihdr = 'IHDR' . pack('NNCCCCC', 0, 0, 8, 2, 0, 0, 0);
    $iend = 'IEND';
    file_put_contents(
        $invalid,
        "\x89PNG\r\n\x1a\n"
        . pack('N', 13) . $ihdr . pack('N', crc32($ihdr))
        . pack('N', 0) . $iend . pack('N', crc32($iend))
    );
    hub_test_assert(
        hub_test_branding_error($db, [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $invalid,
            'size' => filesize($invalid),
            'name' => 'logo.png',
        ]) === 'branding_invalid_image',
        'invalid branding image rejection mismatch'
    );
});

hub_test('branding preserves the previous asset until its setting update succeeds', function (): void {
    $db = hub_test_reset_db();
    $firstUpload = hub_test_branding_upload(HUB_ROOT . '/packs/image-birefnet/demo/acceptance/person_hair.png');
    $first = hub_branding_store_logo($db, $firstUpload);
    unlink($firstUpload['tmp_name']);

    $db->exec(
        "CREATE TRIGGER branding_setting_block
         BEFORE UPDATE ON settings
         WHEN OLD.key = 'AIHUB_BRANDING_LOGO_FILE'
         BEGIN SELECT RAISE(ABORT, 'blocked'); END"
    );
    $secondUpload = hub_test_branding_upload(HUB_ROOT . '/packs/image-birefnet/demo/acceptance/white_product.png');
    try {
        hub_test_assert(hub_test_throws(fn () => hub_branding_store_logo($db, $secondUpload)), 'blocked setting update must fail');
        hub_test_assert(is_file($first['path']), 'previous branding asset was removed before setting update');
        hub_test_assert(
            hub_get_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE') === basename($first['path']),
            'failed setting update changed the active basename'
        );
        hub_test_assert(count(glob(hub_branding_root() . '/logo-*') ?: []) === 1, 'failed setting update leaked a new managed file');
    } finally {
        if (is_file($secondUpload['tmp_name'])) {
            unlink($secondUpload['tmp_name']);
        }
        $db->exec('DROP TRIGGER IF EXISTS branding_setting_block');
        hub_branding_restore_default($db);
    }
});

hub_test('branding replacement and restore delete managed files only', function (): void {
    $db = hub_test_reset_db();
    $firstUpload = hub_test_branding_upload(HUB_ROOT . '/packs/image-birefnet/demo/acceptance/person_hair.png');
    $first = hub_branding_store_logo($db, $firstUpload);
    unlink($firstUpload['tmp_name']);
    $secondUpload = hub_test_branding_upload(HUB_ROOT . '/packs/image-birefnet/demo/acceptance/white_product.png');
    $second = hub_branding_store_logo($db, $secondUpload);
    unlink($secondUpload['tmp_name']);

    hub_test_assert(!file_exists($first['path']) && is_file($second['path']), 'branding replacement cleanup mismatch');

    $outside = tempnam(sys_get_temp_dir(), 'brand_outside_');
    hub_test_assert($outside !== false, 'cannot create outside fixture');
    file_put_contents($outside, 'keep');
    hub_set_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE', '../' . basename($outside));
    $fallback = hub_branding_active_asset($db);
    hub_test_assert($fallback['managed'] === false && $fallback['path'] === HUB_ROOT . '/assets/images/logo.svg', 'path traversal must use default branding');
    hub_branding_restore_default($db);
    hub_test_assert(is_file($outside), 'restore deleted a file outside the branding root');
    unlink($outside);

    hub_set_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE', basename($second['path']));
    hub_branding_restore_default($db);
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE') === '', 'restore did not clear branding setting');
    hub_test_assert(!file_exists($second['path']), 'restore did not remove managed branding file');
});

hub_test('branding ignores symlinked managed files and missing assets', function (): void {
    $db = hub_test_reset_db();
    $root = hub_branding_root();
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException('Cannot create branding test root.');
    }

    $outside = tempnam(sys_get_temp_dir(), 'brand_link_');
    hub_test_assert($outside !== false, 'cannot create symlink target');
    copy(HUB_ROOT . '/packs/image-birefnet/demo/acceptance/person_hair.png', $outside);
    $basename = 'logo-' . str_repeat('a', 32) . '.png';
    $link = $root . '/' . $basename;
    if (function_exists('symlink') && @symlink($outside, $link)) {
        hub_set_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE', $basename);
        hub_test_assert(hub_branding_active_asset($db)['managed'] === false, 'symlinked branding asset must not be served');
        hub_branding_restore_default($db);
        hub_test_assert(is_file($outside), 'restore followed a branding symlink');
        if (is_link($link)) {
            unlink($link);
        }
    }

    hub_set_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE', 'logo-' . str_repeat('b', 32) . '.png');
    hub_test_assert(hub_branding_active_asset($db)['managed'] === false, 'missing branding asset must fail safely');
    hub_branding_restore_default($db);
    unlink($outside);
});

hub_test('branding regular temp files are accepted in tests but rejected by production semantics', function (): void {
    $db = hub_test_reset_db();
    $script = <<<'PHP'
require $argv[1] . '/app/bootstrap.php';
$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$tmp = tempnam(sys_get_temp_dir(), 'brand_prod_');
copy($argv[1] . '/packs/image-birefnet/demo/acceptance/person_hair.png', $tmp);
try {
    hub_branding_store_logo($db, [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'size' => filesize($tmp),
        'name' => 'logo.png',
    ]);
    echo 'accepted';
} catch (RuntimeException $error) {
    echo $error->getMessage();
} finally {
    if (is_file($tmp)) {
        unlink($tmp);
    }
}
PHP;
    $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' ' . escapeshellarg(HUB_ROOT);
    $output = shell_exec($command);
    hub_test_assert(trim((string)$output) === 'branding_upload_invalid', 'production upload semantics accepted a regular temp file');
});

hub_test('branding endpoint declares a pathless cache and conditional response contract', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/branding_asset.php');

    foreach (['Content-Type:', 'Content-Length:', 'Cache-Control: public, max-age=300', 'ETag:', 'X-Content-Type-Options: nosniff', 'HTTP_IF_NONE_MATCH', 'http_response_code(304)'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'branding endpoint contract missing ' . $needle);
    }
    foreach (['$_GET', '$_POST', '$_REQUEST'] as $requestInput) {
        hub_test_assert(!str_contains($source, $requestInput), 'branding endpoint must not accept a path parameter');
    }
});
