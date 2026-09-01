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

function hub_test_breezy_binding_error(callable $callback): ?string
{
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        return $error->getMessage();
    }

    return null;
}

hub_test('Breezy preset binding migration creates the private binding table and lookup index', function (): void {
    $db = hub_test_reset_db();
    $columns = array_column($db->query('PRAGMA table_info(voice_preset_engine_bindings)')->fetchAll(), 'name');
    $indexes = array_column($db->query("PRAGMA index_list('voice_preset_engine_bindings')")->fetchAll(), 'name');

    hub_test_assert(
        $columns === ['voice_preset_id', 'pack_id', 'compatibility_state', 'created_at', 'updated_at']
        && in_array('idx_voice_preset_engine_bindings_pack', $indexes, true)
        && hub_runtime_schema_missing($db) === [],
        'fresh migration must create the complete private preset engine binding schema'
    );
});

hub_test('Breezy preset binding migration repairs the additive table on a marked current database', function (): void {
    $db = hub_test_reset_db();
    $db->exec('DROP TABLE voice_preset_engine_bindings');
    hub_db_mark_migration_current($db);
    hub_migrate($db);

    $indexes = array_column($db->query("PRAGMA index_list('voice_preset_engine_bindings')")->fetchAll(), 'name');
    hub_test_assert(
        hub_runtime_schema_missing($db) === [] && in_array('idx_voice_preset_engine_bindings_pack', $indexes, true),
        'current databases must receive the additive private binding table and lookup index'
    );
});

hub_test('Breezy preset binding keeps VoxCPM2 default private and makes Breezy explicit', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
    hub_install_pack($db, 'tts-breezyvoice', ['idempotent' => true]);
    $member = hub_create_api_member($db, 'Breezy Owner');
    $token = hub_create_api_token($db, $member, 'Breezy binding token', null, null);
    $profile = hub_test_breezy_confirmed_profile($db, $member);
    hub_test_breezy_preset($db, $member, $profile);

    hub_test_assert(
        hub_voice_preset_engine_binding_for_owner($db, $member, 'mechanic-dad') ===
            ['pack_id' => 'tts-voxcpm2', 'explicit' => false],
        'legacy preset must remain VoxCPM2'
    );
    $result = hub_voice_preset_engine_bind($db, ['member_id' => $member, 'token_id' => (int)$token['token_id']], [
        'voice_preset' => 'mechanic-dad',
        'engine' => 'breezyvoice',
    ]);
    hub_test_assert(($result['preset']['preset_revision'] ?? null) === 2, 'bind increments revision');
    hub_test_assert(
        hub_voice_preset_engine_binding_for_owner($db, $member, 'mechanic-dad') ===
            ['pack_id' => 'tts-breezyvoice', 'explicit' => true],
        'Breezy must be explicit'
    );

    $again = hub_voice_preset_engine_bind($db, ['member_id' => $member, 'token_id' => (int)$token['token_id']], [
        'voice_preset' => 'mechanic-dad',
        'engine' => 'breezyvoice',
    ]);
    $audit = $db->query(
        "SELECT voice_profile_id, owner_member_id, token_id, action, mode, details_json
         FROM voice_profile_audit_logs
         WHERE action = 'preset_engine_bind'
         ORDER BY id ASC"
    )->fetchAll();
    $auditDetails = array_map(
        static fn (array $row): mixed => json_decode((string)($row['details_json'] ?? ''), true),
        $audit
    );
    $public = $again['preset'] ?? [];

    hub_test_assert(
        ($again['preset']['preset_revision'] ?? null) === 2
        && count($audit) === 2
        && $auditDetails === [
            ['voice_preset' => 'mechanic-dad', 'pack_id' => 'tts-breezyvoice', 'preset_revision' => 2],
            ['voice_preset' => 'mechanic-dad', 'pack_id' => 'tts-breezyvoice', 'preset_revision' => 2],
        ]
        && array_map(static fn (array $row): int => (int)($row['voice_profile_id'] ?? 0), $audit) === [$profile, $profile]
        && array_map(static fn (array $row): int => (int)($row['owner_member_id'] ?? 0), $audit) === [$member, $member]
        && array_map(static fn (array $row): int => (int)($row['token_id'] ?? 0), $audit) === [(int)$token['token_id'], (int)$token['token_id']]
        && array_column($audit, 'mode') === [null, null]
        && array_keys($public) === ['id', 'label', 'gender', 'age_bucket', 'purposes', 'scenes', 'preset_revision'],
        'idempotent rebind must keep the revision and audit only safe binding metadata'
    );
    foreach (['engine', 'pack_id', 'voice_profile_id', 'base_voice_profile_id', 'reference', 'sha256', 'transcript', 'prompt', 'path'] as $privateField) {
        hub_test_assert(!array_key_exists($privateField, $public), 'public preset must not disclose ' . $privateField);
    }
});

hub_test('Breezy preset binding refuses incompatible base profiles without a fallback or mutation', function (): void {
    $db = hub_test_reset_db();
    $member = hub_create_api_member($db, 'Breezy incompatible owner');
    $profileId = hub_test_breezy_confirmed_profile($db, $member);
    hub_test_breezy_preset($db, $member, $profileId);
    $db->prepare("UPDATE voice_profiles SET transcription_status = 'ready', prompt_text_confirmed_at = NULL WHERE id = :id")
        ->execute([':id' => $profileId]);

    $error = hub_test_breezy_binding_error(static fn (): array => hub_voice_preset_engine_bind($db, ['member_id' => $member], [
        'voice_preset' => 'mechanic-dad',
        'engine' => 'breezyvoice',
    ]));
    $db->prepare("UPDATE voice_profiles SET transcription_status = 'confirmed', prompt_text = NULL, prompt_text_confirmed_at = :confirmed_at WHERE id = :id")
        ->execute([':confirmed_at' => hub_now(), ':id' => $profileId]);
    $missingTranscript = hub_test_breezy_binding_error(static fn (): array => hub_voice_preset_engine_bind($db, ['member_id' => $member], [
        'voice_preset' => 'mechanic-dad',
        'engine' => 'breezyvoice',
    ]));
    $preset = hub_voice_preset_for_owner($db, $member, 'mechanic-dad');
    $bindingCount = (int)$db->query('SELECT COUNT(*) FROM voice_preset_engine_bindings')->fetchColumn();
    $auditCount = (int)$db->query("SELECT COUNT(*) FROM voice_profile_audit_logs WHERE action = 'preset_engine_bind'")->fetchColumn();

    hub_test_assert(
        $error === 'voice_preset_engine_incompatible'
        && $missingTranscript === 'voice_preset_engine_incompatible'
        && (int)($preset['revision'] ?? 0) === 1
        && $bindingCount === 0
        && $auditCount === 0,
        'unconfirmed profile must reject Breezy without binding, revision, audit, or VoxCPM2 fallback'
    );
});

hub_test('Breezy profile compatibility requires owner private consented confirmed permanent zh-TW data', function (): void {
    $now = hub_now();
    $profile = [
        'id' => 1,
        'owner_member_id' => 71,
        'visibility' => 'private',
        'usage_scope' => 'private',
        'consent_type' => 'self_recorded',
        'transcription_status' => 'confirmed',
        'prompt_text' => 'confirmed transcript fixture',
        'prompt_text_confirmed_at' => $now,
        'language' => 'zh-TW',
        'deleted_at' => null,
        'expires_at' => null,
    ];
    $invalid = [
        ['owner_member_id' => 72],
        ['visibility' => 'shared'],
        ['usage_scope' => 'licensed'],
        ['consent_type' => ''],
        ['transcription_status' => 'ready'],
        ['prompt_text' => ''],
        ['prompt_text_confirmed_at' => ''],
        ['language' => 'en'],
        ['deleted_at' => $now],
        ['expires_at' => '2099-01-01 00:00:00'],
    ];

    hub_test_assert(hub_voice_preset_breezy_profile_is_compatible($profile, 71), 'complete private confirmed zh-TW base profile must be compatible');
    foreach ($invalid as $changes) {
        hub_test_assert(!hub_voice_preset_breezy_profile_is_compatible(array_replace($profile, $changes), 71), 'each privacy, ownership, transcript, expiry, and language guard must reject');
    }
});

hub_test('Breezy preset binding lookup rejects unknown or non-ready persisted rows', function (): void {
    $db = hub_test_reset_db();
    $member = hub_create_api_member($db, 'Breezy persisted binding owner');
    $profileId = hub_test_breezy_confirmed_profile($db, $member);
    hub_test_breezy_preset($db, $member, $profileId);
    $preset = hub_voice_preset_for_owner($db, $member, 'mechanic-dad') ?? throw new RuntimeException('Missing binding fixture preset.');
    $insert = $db->prepare(
        'INSERT INTO voice_preset_engine_bindings
            (voice_preset_id, pack_id, compatibility_state, created_at, updated_at)
         VALUES
            (:voice_preset_id, :pack_id, :compatibility_state, :created_at, :updated_at)'
    );
    $insert->execute([
        ':voice_preset_id' => (int)$preset['id'],
        ':pack_id' => 'unrecognized-pack',
        ':compatibility_state' => 'ready',
        ':created_at' => hub_now(),
        ':updated_at' => hub_now(),
    ]);
    $unknownPack = hub_test_breezy_binding_error(static fn (): array => hub_voice_preset_engine_binding_for_preset($db, $preset));
    $db->prepare(
        "UPDATE voice_preset_engine_bindings
         SET pack_id = 'tts-breezyvoice', compatibility_state = 'blocked'
         WHERE voice_preset_id = :voice_preset_id"
    )->execute([':voice_preset_id' => (int)$preset['id']]);
    $nonReady = hub_test_breezy_binding_error(static fn (): array => hub_voice_preset_engine_binding_for_preset($db, $preset));

    hub_test_assert(
        $unknownPack === 'voice_preset_engine_incompatible' && $nonReady === 'voice_preset_engine_incompatible',
        'persisted bindings must accept only known ready packs'
    );
});

hub_test('Breezy preset binding validates engine payload and owner boundaries', function (): void {
    $db = hub_test_reset_db();
    $owner = hub_create_api_member($db, 'Breezy bind owner');
    $other = hub_create_api_member($db, 'Breezy bind other');
    $profileId = hub_test_breezy_confirmed_profile($db, $owner);
    hub_test_breezy_preset($db, $owner, $profileId);

    $badEngine = hub_test_breezy_binding_error(static fn (): array => hub_voice_preset_engine_bind($db, ['member_id' => $owner], [
        'voice_preset' => 'mechanic-dad',
        'engine' => 'unknown-engine',
    ]));
    $unknownField = hub_test_breezy_binding_error(static fn (): array => hub_voice_preset_engine_bind($db, ['member_id' => $owner], [
        'voice_preset' => 'mechanic-dad',
        'engine' => 'breezyvoice',
        'pack_id' => 'tts-breezyvoice',
    ]));
    $foreignPreset = hub_test_breezy_binding_error(static fn (): array => hub_voice_preset_engine_bind($db, ['member_id' => $other], [
        'voice_preset' => 'mechanic-dad',
        'engine' => 'breezyvoice',
    ]));

    hub_test_assert(
        hub_voice_preset_engine_pack_id('voxcpm2') === 'tts-voxcpm2'
        && hub_voice_preset_engine_pack_id('breezyvoice') === 'tts-breezyvoice'
        && hub_voice_preset_engine_pack_id('BreezyVoice') === null
        && $badEngine === 'voice_preset_invalid'
        && $unknownField === 'voice_preset_invalid'
        && $foreignPreset === 'voice_preset_not_found',
        'binding must reject unknown engines and payload keys while preserving owner-scoped not-found behavior'
    );
});
