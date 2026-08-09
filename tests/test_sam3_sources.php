<?php
declare(strict_types=1);

hub_test('SAM3 source URLs allow only private RTSP cameras or allowlisted HTTPS HLS', function (): void {
    hub_test_assert(
        hub_sam3_normalize_source_url('rtsps://192.168.10.20:8554/live', []) === ['protocol' => 'rtsps', 'url' => 'rtsps://192.168.10.20:8554/live'],
        'private RTSP source must be accepted without rewriting its path'
    );
    hub_test_assert(
        hub_sam3_normalize_source_url('https://cams.example.test/live/channel.m3u8', ['cams.example.test']) === ['protocol' => 'hls', 'url' => 'https://cams.example.test/live/channel.m3u8'],
        'allowlisted HLS source must be accepted'
    );
    foreach ([
        'rtsp://viewer:secret@192.168.10.20/live',
        'rtsp://198.51.100.12/live',
        'rtsp://camera.internal/live',
        'https://cams.example.test/live/channel.m3u8?token=secret',
        'https://other.example.test/live/channel.m3u8',
        'https://cams.example.test/live/channel.mp4',
    ] as $url) {
        hub_test_assert(hub_sam3_normalize_source_url($url, ['cams.example.test']) === null, 'unsafe SAM3 source must be rejected');
    }
});

hub_test('SAM3 HLS sources require their dedicated administrator allowlist', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'sam3', [
        'service_key' => 'sam3-hls-main', 'mode' => 'sam3', 'name' => 'SAM3 HLS Main', 'port_mode' => 'manual', 'local_port' => 18165,
    ])['service'];
    hub_test_assert(hub_test_throws(static fn (): array => hub_sam3_create_source($db, (int)$service['id'], 'HLS', 'https://cams.example.test/live.m3u8', 15, 1)), 'HLS source must require a dedicated allowlist entry');
    hub_sam3_save_hls_allowed_hosts($db, 'admin', "cams.example.test\n");
    $source = hub_sam3_create_source($db, (int)$service['id'], 'HLS', 'https://cams.example.test/live.m3u8', 15, 1);
    hub_test_assert(($source['protocol'] ?? '') === 'hls', 'allowlisted HLS source must be accepted');
});

hub_test('SAM3 source registry keeps stream URLs inside the control plane', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'sam3', [
        'service_key' => 'sam3-source-main',
        'mode' => 'sam3',
        'name' => 'SAM3 Source Main',
        'port_mode' => 'manual',
        'local_port' => 18164,
    ])['service'];
    $source = hub_sam3_create_source($db, (int)$service['id'], 'Gate camera', 'rtsp://192.168.1.20/live', 15, 1);
    $sourceId = (string)$source['source_id'];
    hub_test_assert(($source['source_url'] ?? '') === 'rtsp://192.168.1.20/live', 'admin creation must retain the canonical source URL');
    hub_test_assert(!array_key_exists('source_url', hub_sam3_get_source($db, $sourceId, (int)$service['id']) ?? []), 'public source records must omit the stream URL');
    hub_test_assert((hub_sam3_source_for_task($db, $sourceId, (int)$service['id'])['source_url'] ?? '') === 'rtsp://192.168.1.20/live', 'trusted task lookup must resolve the source URL');
    hub_sam3_set_source_enabled($db, $sourceId, (int)$service['id'], false);
    hub_test_assert(hub_sam3_source_for_task($db, $sourceId, (int)$service['id']) === null, 'disabled sources must not reach a task runner');
});

hub_test('SAM3 capture command has fixed bounds and no shell interpolation', function (): void {
    $command = hub_sam3_capture_command([
        'source_url' => 'rtsps://192.168.1.20:8554/live',
        'protocol' => 'rtsps',
        'clip_seconds' => 15,
    ], '/tmp/sam3-capture.mp4');
    hub_test_assert($command[0] === 'ffmpeg' && in_array('-rw_timeout', $command, true) && in_array('-fs', $command, true), 'capture must retain its fixed FFmpeg time and size bounds');
    hub_test_assert(in_array('-rtsp_transport', $command, true) && in_array('tcp', $command, true), 'RTSP capture must use TCP transport');
    hub_test_assert(!in_array('sh', $command, true) && !in_array('-c', $command, true), 'capture must use argv execution rather than a shell');
    hub_test_assert(hub_test_throws(static fn (): array => hub_sam3_capture_command([
        'source_url' => 'https://cams.example.test/live.m3u8', 'protocol' => 'hls', 'clip_seconds' => 15,
    ], '/tmp/sam3-capture.mp4')), 'HLS capture must fail closed until every playlist target is revalidated');
});
