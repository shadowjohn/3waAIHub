<?php
declare(strict_types=1);

function hub_audio_modes(): array
{
    return [
        'audio_upload' => 'Audio Upload',
        'audio' => 'Audio Understanding',
    ];
}

function hub_audio_generate_audio_id(): string
{
    return 'aud_' . rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}

function hub_audio_upload_root(): string
{
    if (defined('HUB_TESTING') && HUB_TESTING) {
        static $testDir = null;
        if ($testDir === null) {
            $testDir = sys_get_temp_dir() . '/3waaihub_test_audio_assets_' . bin2hex(random_bytes(16));
            if (!mkdir($testDir, 0700) || is_link($testDir)) {
                throw new RuntimeException('Cannot create test audio asset directory.');
            }
        }

        return $testDir;
    }

    return HUB_DATA_DIR . '/uploads/audio';
}

function hub_audio_wav_metadata(string $path): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('invalid_audio');
    }
    try {
        $header = fread($fh, 12);
        if ($header === false || strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
            throw new RuntimeException('invalid_audio');
        }

        $fmt = null;
        $dataBytes = null;
        while (!feof($fh)) {
            $chunkHeader = fread($fh, 8);
            if ($chunkHeader === false || strlen($chunkHeader) < 8) {
                break;
            }
            $chunkId = substr($chunkHeader, 0, 4);
            $chunkSize = (int)unpack('V', substr($chunkHeader, 4, 4))[1];
            if ($chunkSize < 0) {
                throw new RuntimeException('invalid_audio');
            }
            if ($chunkId === 'fmt ') {
                $raw = fread($fh, $chunkSize);
                if ($raw === false || strlen($raw) < 16) {
                    throw new RuntimeException('invalid_audio');
                }
                $fmt = unpack('vformat/vchannels/Vsample_rate/Vbyte_rate/vblock_align/vbits_per_sample', substr($raw, 0, 16));
            } elseif ($chunkId === 'data') {
                $dataBytes = $chunkSize;
                fseek($fh, $chunkSize, SEEK_CUR);
            } else {
                fseek($fh, $chunkSize, SEEK_CUR);
            }
            if (($chunkSize % 2) === 1) {
                fseek($fh, 1, SEEK_CUR);
            }
            if ($fmt !== null && $dataBytes !== null) {
                break;
            }
        }
        if ($fmt === null || $dataBytes === null || (int)$fmt['byte_rate'] < 1) {
            throw new RuntimeException('invalid_audio');
        }
        if ((int)$fmt['format'] !== 1 || (int)$fmt['sample_rate'] !== 16000 || (int)$fmt['channels'] !== 1) {
            throw new RuntimeException('unsupported_audio_format');
        }
        $durationMs = (int)round(($dataBytes / (int)$fmt['byte_rate']) * 1000);
        if ($durationMs < 1) {
            throw new RuntimeException('invalid_audio');
        }
        if ($durationMs > 30000) {
            throw new RuntimeException('audio_too_long');
        }

        return [
            'duration_ms' => $durationMs,
            'sample_rate' => (int)$fmt['sample_rate'],
            'channels' => (int)$fmt['channels'],
        ];
    } finally {
        fclose($fh);
    }
}

function hub_audio_store_upload(PDO $db, array $file, array $authContext): array
{
    if (empty($authContext['member_id']) && empty($authContext['token_id'])) {
        throw new RuntimeException('audio_owner_unavailable');
    }
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('file_required');
    }

    $tmpName = (string)$file['tmp_name'];
    $size = (int)($file['size'] ?? filesize($tmpName));
    if ($size < 1 || $size > 16 * 1024 * 1024) {
        throw new RuntimeException('payload_too_large');
    }

    $meta = hub_audio_wav_metadata($tmpName);
    $audioId = hub_audio_generate_audio_id();
    $relDir = 'uploads/audio/' . $audioId;
    $dir = hub_audio_upload_root() . '/' . $audioId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('storage_failed');
    }
    $path = $dir . '/original.wav';
    $ok = is_uploaded_file($tmpName) ? move_uploaded_file($tmpName, $path) : copy($tmpName, $path);
    if (!$ok) {
        throw new RuntimeException('upload_failed');
    }

    $createdAt = hub_now();
    $expiresAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +7 days'));
    $row = [
        'audio_id' => $audioId,
        'owner_member_id' => !empty($authContext['member_id']) ? (int)$authContext['member_id'] : null,
        'owner_token_id' => !empty($authContext['token_id']) ? (int)$authContext['token_id'] : null,
        'mime' => 'audio/wav',
        'byte_size' => filesize($path) ?: $size,
        'duration_ms' => (int)$meta['duration_ms'],
        'sample_rate' => (int)$meta['sample_rate'],
        'channels' => (int)$meta['channels'],
        'sha256' => hash_file('sha256', $path),
        'storage_relpath' => $relDir . '/original.wav',
        'expires_at' => $expiresAt,
        'created_at' => $createdAt,
    ];
    try {
        $stmt = $db->prepare(
            'INSERT INTO audio_assets
                (audio_id, owner_member_id, owner_token_id, mime, byte_size, duration_ms, sample_rate, channels, sha256, storage_relpath, expires_at, created_at)
             VALUES
                (:audio_id, :owner_member_id, :owner_token_id, :mime, :byte_size, :duration_ms, :sample_rate, :channels, :sha256, :storage_relpath, :expires_at, :created_at)'
        );
        $stmt->execute($row);
    } catch (Throwable $e) {
        @unlink($path);
        @rmdir($dir);
        throw $e;
    }

    return $row + ['size' => $row['byte_size']];
}

function hub_audio_get_asset_for_auth(PDO $db, string $audioId, array $authContext): ?array
{
    if (!preg_match('/^aud_[A-Za-z0-9_-]{20,64}$/', $audioId)) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM audio_assets WHERE audio_id = :audio_id LIMIT 1');
    $stmt->execute([':audio_id' => $audioId]);
    $asset = $stmt->fetch();
    if (!$asset || (string)$asset['expires_at'] < hub_now()) {
        return null;
    }

    $memberId = (int)($authContext['member_id'] ?? 0);
    $tokenId = (int)($authContext['token_id'] ?? 0);
    $ownerMemberId = (int)($asset['owner_member_id'] ?? 0);
    $ownerTokenId = (int)($asset['owner_token_id'] ?? 0);
    $allowed = ($memberId > 0 && $ownerMemberId > 0 && $memberId === $ownerMemberId)
        || ($ownerMemberId === 0 && $tokenId > 0 && $ownerTokenId > 0 && $tokenId === $ownerTokenId);
    if (!$allowed) {
        return null;
    }

    $db->prepare('UPDATE audio_assets SET last_accessed_at = :now WHERE id = :id')
        ->execute([':now' => hub_now(), ':id' => (int)$asset['id']]);

    return $asset;
}

function hub_audio_asset_host_path(array $asset): ?string
{
    $root = realpath(hub_audio_upload_root());
    if ($root === false) {
        return null;
    }
    $storageRelpath = ltrim((string)($asset['storage_relpath'] ?? ''), '/');
    $prefix = 'uploads/audio/';
    if (!str_starts_with($storageRelpath, $prefix)) {
        return null;
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . substr($storageRelpath, strlen($prefix)));
    if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}
