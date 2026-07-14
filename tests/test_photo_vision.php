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

hub_test('Photo upload and ask are protected internal Gateway modes', function (): void {
    $db = hub_test_reset_db();
    hub_ensure_default_storage_settings($db);
    $memberId = hub_create_api_member($db, 'Photo API', '', 'photo-api@example.test', '');
    $token = hub_create_api_token($db, $memberId, 'Photo token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'photo_upload', null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'photo', null);
    hub_add_api_token_ip_rule($db, (int)$token['token_id'], '203.0.113.30', '');

    $tmp = tempnam(sys_get_temp_dir(), 'photo_');
    imagepng(imagecreatetruecolor(6, 6), $tmp);
    [$oldServer, $oldFiles, $oldPost] = [$_SERVER, $_FILES, $_POST];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.30';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['plain_token'];
    $_FILES = ['image' => ['name' => 'ok.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
    $_POST = [];
    $upload = hub_gateway_dispatch($db, 'photo_upload');
    $_SERVER = $oldServer; $_FILES = $oldFiles; $_POST = $oldPost;

    $uploadPayload = json_decode((string)$upload['body'], true);
    hub_test_assert((int)$upload['status'] === 200, 'photo_upload must pass');
    hub_test_assert(($uploadPayload['ok'] ?? false) === true, 'photo_upload ok missing');
    hub_test_assert(preg_match('/^img_/', (string)($uploadPayload['image_id'] ?? '')) === 1, 'image_id missing');

    [$oldServer, $oldFiles, $oldPost] = [$_SERVER, $_FILES, $_POST];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.30';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['plain_token'];
    $_FILES = [];
    $_POST = [];
    $response = hub_gateway_dispatch($db, 'photo');
    $_SERVER = $oldServer; $_FILES = $oldFiles; $_POST = $oldPost;
    hub_test_assert((int)$response['status'] === 400, 'photo gateway must be routable and validate JSON body');
});

hub_test('Photo customer permission grants upload helper mode with photo', function (): void {
    $db = hub_test_reset_db();
    $customerId = hub_create_customer_user($db, [
        'username' => 'photo_customer',
        'password' => 'password123',
        'display_name' => 'Photo Customer',
        'modes' => ['photo'],
    ]);

    $token = hub_create_customer_token($db, $customerId, 'Photo customer token');
    $modes = array_column(hub_list_api_token_permissions($db, (int)$token['token_id']), 'mode');
    sort($modes);

    hub_test_assert($modes === ['photo', 'photo_upload'], 'photo customer token must include photo_upload');
});

hub_test('Photo request payload uses server path and safe defaults', function (): void {
    $db = hub_test_reset_db();
    $payload = hub_photo_request_payload($db, ['image_id' => 'img_payload_default'], ['text' => '  describe this  ']);

    hub_test_assert($payload['image_internal_path'] === '/data/photo/img_payload_default/original', 'image_internal_path must be server-derived');
    hub_test_assert($payload['max_tokens'] === 256, 'max_tokens must default to 256');
    hub_test_assert($payload['real_inference'] === false, 'real_inference must default false');
    hub_test_assert($payload['text'] === 'describe this', 'text must be trimmed');
});

hub_test('Photo proxy success response is normalized and errors are preserved', function (): void {
    $success = hub_photo_normalize_proxy_response(hub_gateway_json(200, [
        'ok' => true,
        'image_id' => 'img_schema',
        'answer' => 'A small image.',
    ]), 'img_schema');
    $body = json_decode((string)$success['body'], true);

    foreach (['ok', 'mock', 'runtime_level', 'model', 'image_id', 'answer', 'caption', 'tags', 'usage', 'elapsed_ms'] as $key) {
        hub_test_assert(array_key_exists($key, $body), 'photo success response missing ' . $key);
    }
    hub_test_assert($body['ok'] === true, 'ok must stay true');
    hub_test_assert($body['mock'] === false, 'mock must default false');
    hub_test_assert($body['runtime_level'] === 'L5-benchmark-ready', 'runtime_level default mismatch');
    hub_test_assert($body['model'] === 'gemma4-12b', 'model default mismatch');
    hub_test_assert($body['caption'] === '', 'caption must default empty string');
    hub_test_assert($body['tags'] === [], 'tags must default empty array');
    hub_test_assert($body['usage'] === ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0], 'usage default mismatch');
    hub_test_assert($body['elapsed_ms'] === 0, 'elapsed_ms must default 0');

    $error = hub_photo_normalize_proxy_response(hub_gateway_json(200, [
        'ok' => false,
        'error' => 'model_failed',
        'message' => 'upstream failed',
    ]), 'img_schema');
    $errorBody = json_decode((string)$error['body'], true);
    hub_test_assert($errorBody === ['ok' => false, 'error' => 'model_failed', 'message' => 'upstream failed'], 'ok=false response must be preserved');
});

hub_test('Photo prune dry-run does not delete active files and apply deletes expired rows', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Prune Owner', '', 'prune@example.test', '');
    $tmp = tempnam(sys_get_temp_dir(), 'photo_');
    imagepng(imagecreatetruecolor(3, 3), $tmp);
    $asset = hub_photo_store_upload($db, [
        'name' => 'a.png',
        'type' => 'image/png',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmp),
    ], ['member_id' => (int)$memberId]);
    $db->prepare('UPDATE photo_assets SET expires_at = :expires_at WHERE image_id = :image_id')
        ->execute([':expires_at' => '2000-01-01 00:00:00', ':image_id' => $asset['image_id']]);

    $dry = hub_photo_prune_expired($db, true, 100);
    hub_test_assert((int)$dry['matched'] === 1 && (int)$dry['rows_deleted'] === 0, 'dry run must not delete rows');
    hub_test_assert(is_file(HUB_DATA_DIR . '/' . $asset['storage_relpath']), 'dry run must not delete file');
    $applied = hub_photo_prune_expired($db, false, 100);
    hub_test_assert((int)$applied['rows_deleted'] === 1, 'apply must delete expired row');
    hub_test_assert(!is_file(HUB_DATA_DIR . '/' . $asset['storage_relpath']), 'expired file must be deleted');
});
