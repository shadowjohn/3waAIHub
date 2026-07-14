<?php
declare(strict_types=1);

hub_test('Photo asset upload stores validated image with owner and TTL', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Photo Owner', '', 'photo@example.test', '');
    $token = hub_create_api_token($db, $memberId, 'photo token', null, null);
    $tmp = tempnam(sys_get_temp_dir(), 'photo_');
    imagepng(imagecreatetruecolor(16, 8), $tmp);

    $asset = hub_photo_store_upload($db, [
        'name' => 'client-name.png',
        'type' => 'image/jpeg',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmp),
    ], ['member_id' => $memberId, 'token_id' => (int)$token['token_id']]);

    hub_test_assert(preg_match('/^img_[A-Za-z0-9_-]{20,64}$/', (string)$asset['image_id']) === 1, 'image_id format mismatch');
    hub_test_assert((string)$asset['mime'] === 'image/png', 'real MIME must be detected from file');
    hub_test_assert((int)$asset['width'] === 16 && (int)$asset['height'] === 8, 'image dimensions mismatch');
    hub_test_assert(is_file(HUB_DATA_DIR . '/' . $asset['storage_relpath']), 'stored photo missing');
    hub_test_assert(!str_contains((string)$asset['storage_relpath'], 'client-name'), 'client filename must not be used as storage path');
});

hub_test('Photo asset validation rejects unsafe uploads and enforces ownership', function (): void {
    $db = hub_test_reset_db();
    $memberA = hub_create_api_member($db, 'A', '', 'a@example.test', '');
    $memberB = hub_create_api_member($db, 'B', '', 'b@example.test', '');
    $tokenA = hub_create_api_token($db, $memberA, 'A token', null, null);
    $tokenB = hub_create_api_token($db, $memberB, 'B token', null, null);
    $fake = tempnam(sys_get_temp_dir(), 'fake_');
    file_put_contents($fake, 'not an image');

    hub_test_assert(hub_test_throws(fn () => hub_photo_store_upload($db, [
        'name' => 'fake.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $fake,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($fake),
    ], ['member_id' => $memberA, 'token_id' => (int)$tokenA['token_id']])), 'fake image must be rejected');

    $tmp = tempnam(sys_get_temp_dir(), 'photo_');
    imagepng(imagecreatetruecolor(4, 4), $tmp);
    $asset = hub_photo_store_upload($db, [
        'name' => 'ok.png',
        'type' => 'image/png',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmp),
    ], ['member_id' => $memberA, 'token_id' => (int)$tokenA['token_id']]);

    hub_test_assert(hub_photo_get_asset_for_auth($db, (string)$asset['image_id'], ['member_id' => $memberA]) !== null, 'owner member must read asset');
    hub_test_assert(hub_photo_get_asset_for_auth($db, (string)$asset['image_id'], ['member_id' => $memberB, 'token_id' => (int)$tokenB['token_id']]) === null, 'other member must not read asset');
});
