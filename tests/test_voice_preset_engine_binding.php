<?php
declare(strict_types=1);

function hub_test_breezy_write_pcm_wav(string $path, int $sampleRate, array $samples): void
{
    if ($sampleRate < 1 || $samples === []) {
        throw new InvalidArgumentException('Invalid BreezyVoice PCM fixture.');
    }
    $frames = '';
    foreach ($samples as $sample) {
        if (!is_int($sample) || $sample < -32768 || $sample > 32767) {
            throw new InvalidArgumentException('Invalid BreezyVoice PCM sample.');
        }
        $frames .= pack('v', $sample & 0xffff);
    }
    $wav = 'RIFF' . pack('V', 36 + strlen($frames)) . 'WAVEfmt '
        . pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
        . 'data' . pack('V', strlen($frames)) . $frames;
    if (file_put_contents($path, $wav) === false) {
        throw new RuntimeException('Cannot write BreezyVoice WAV fixture.');
    }
}

function hub_test_breezy_confirmed_profile(PDO $db, int $memberId): int
{
    $dir = hub_voice_profile_storage_dir();
    $path = $dir . '/breezy_reference.wav';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create fixture directory.');
    }
    hub_test_breezy_write_pcm_wav($path, 16000, [0, 800, -800, 0]);
    $id = hub_create_voice_profile($db, $memberId, [
        'name' => 'Breezy fixture',
        'reference_audio_path' => $path,
        'reference_audio_sha256' => hash_file('sha256', $path),
        'reference_contract' => 'generic',
        'prompt_text' => '這是一段已確認的台灣國語測試逐字稿。',
        'prompt_text_confirmed_at' => hub_now(),
        'language' => 'zh-TW',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $db->prepare(
        "UPDATE voice_profiles
         SET transcription_status = 'confirmed', prompt_text_confirmed_at = :confirmed_at
         WHERE id = :id"
    )->execute([':confirmed_at' => hub_now(), ':id' => $id]);

    return $id;
}

function hub_test_breezy_preset(PDO $db, int $memberId, int $profileId, string $presetId = 'mechanic-dad'): void
{
    $now = hub_now();
    $db->prepare(
        'INSERT INTO voice_presets
            (owner_member_id, preset_id, label, gender, age_bucket, purposes_json, scenes_json,
             base_voice_profile_id, revision, enabled, created_at, updated_at)
         VALUES
            (:owner, :preset, :label, :gender, :age, :purposes, :scenes, :profile, 1, 1, :now, :now)'
    )->execute([
        ':owner' => $memberId, ':preset' => $presetId, ':label' => '測試技師', ':gender' => 'male',
        ':age' => 'adult', ':purposes' => '["service_reply"]', ':scenes' => '["default"]',
        ':profile' => $profileId, ':now' => $now,
    ]);
}

hub_test('Breezy preset binding is explicit', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
    hub_install_pack($db, 'tts-breezyvoice', ['idempotent' => true]);
    $member = hub_create_api_member($db, 'Breezy Owner');
    $profile = hub_test_breezy_confirmed_profile($db, $member);
    hub_test_breezy_preset($db, $member, $profile);

    hub_test_assert(
        hub_voice_preset_engine_binding_for_owner($db, $member, 'mechanic-dad') ===
            ['pack_id' => 'tts-voxcpm2', 'explicit' => false],
        'legacy preset must remain VoxCPM2'
    );
    $result = hub_voice_preset_engine_bind($db, ['member_id' => $member], [
        'voice_preset' => 'mechanic-dad',
        'engine' => 'breezyvoice',
    ]);
    hub_test_assert(($result['preset']['preset_revision'] ?? null) === 2, 'bind increments revision');
    hub_test_assert(
        hub_voice_preset_engine_binding_for_owner($db, $member, 'mechanic-dad') ===
            ['pack_id' => 'tts-breezyvoice', 'explicit' => true],
        'Breezy must be explicit'
    );
});
