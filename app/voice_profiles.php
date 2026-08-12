<?php
declare(strict_types=1);

function hub_voice_profile_storage_dir(): string
{
    if (defined('HUB_TESTING') && HUB_TESTING) {
        static $testDir = null;
        if ($testDir === null) {
            $testDir = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . '3waaihub_test_voice_profiles_' . bin2hex(random_bytes(16));
            if (!mkdir($testDir, 0700)) {
                throw new RuntimeException('Cannot create test voice profile directory.');
            }
        }

        return $testDir;
    }

    $dir = HUB_DATA_DIR . '/uploads/voice_profiles';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create voice profile directory.');
    }

    return $dir;
}

const HUB_VOICE_TRANSCRIPT_NORMALIZER_VERSION = 's2twp-strip-punctuation-v1';

function hub_voice_transcript_opencc(string $text): string
{
    $binary = is_executable('/usr/bin/opencc') ? '/usr/bin/opencc' : (is_executable('/usr/local/bin/opencc') ? '/usr/local/bin/opencc' : 'opencc');
    $config = null;
    foreach (['/usr/share/opencc/s2twp.json', '/usr/local/share/opencc/s2twp.json'] as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $config = $candidate;
            break;
        }
    }
    if ($config === null || !function_exists('proc_open')) {
        throw new RuntimeException('voice_profile_transcript_normalization_failed');
    }
    $inputPath = tempnam(sys_get_temp_dir(), 'aihub-transcript-in-');
    $outputPath = tempnam(sys_get_temp_dir(), 'aihub-transcript-out-');
    if ($inputPath === false || $outputPath === false || file_put_contents($inputPath, $text) === false) {
        if (is_string($inputPath)) {
            @unlink($inputPath);
        }
        if (is_string($outputPath)) {
            @unlink($outputPath);
        }
        throw new RuntimeException('voice_profile_transcript_normalization_failed');
    }
    @chmod($inputPath, 0600);
    @chmod($outputPath, 0600);
    $pipes = [];
    $process = @proc_open([$binary, '-i', $inputPath, '-o', $outputPath, '-c', $config], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        @unlink($inputPath);
        @unlink($outputPath);
        throw new RuntimeException('voice_profile_transcript_normalization_failed');
    }
    $error = stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $exitCode = proc_close($process);
    $converted = $exitCode === 0 ? file_get_contents($outputPath) : false;
    @unlink($inputPath);
    @unlink($outputPath);
    if ($converted === false || $exitCode !== 0 || trim((string)$error) !== '') {
        throw new RuntimeException('voice_profile_transcript_normalization_failed');
    }

    return str_replace('臺', '台', $converted);
}

function hub_voice_transcript_normalize(string $text): string
{
    if (preg_match('//u', $text) !== 1) {
        throw new RuntimeException('voice_profile_transcript_normalization_failed');
    }
    if (!class_exists('Normalizer')) {
        throw new RuntimeException('voice_profile_transcript_normalization_failed');
    }
    $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
    if ($normalized === false) {
        throw new RuntimeException('voice_profile_transcript_normalization_failed');
    }
    $text = $normalized;
    $text = hub_voice_transcript_opencc(str_replace(["\r", "\n", "\t"], ' ', $text));
    $text = preg_replace('/[\p{P}]+/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', (string)$text);
    if ($text === null) {
        throw new RuntimeException('voice_profile_transcript_normalization_failed');
    }
    do {
        $previous = $text;
        $text = preg_replace('/([\p{Han}])\s+([\p{Han}])/u', '$1$2', $text);
        if ($text === null) {
            throw new RuntimeException('voice_profile_transcript_normalization_failed');
        }
    } while ($text !== $previous);

    return trim($text);
}

function hub_voice_transcript_cer(string $reference, string $recognized): float
{
    $referenceChars = preg_split('//u', $reference, -1, PREG_SPLIT_NO_EMPTY);
    $recognizedChars = preg_split('//u', $recognized, -1, PREG_SPLIT_NO_EMPTY);
    if ($referenceChars === false || $recognizedChars === false) {
        throw new RuntimeException('voice_profile_transcript_validation_failed');
    }
    $referenceCount = count($referenceChars);
    if ($referenceCount === 0) {
        return count($recognizedChars) === 0 ? 0.0 : 1.0;
    }
    $previous = range(0, count($recognizedChars));
    foreach ($referenceChars as $row => $referenceChar) {
        $current = [$row + 1];
        foreach ($recognizedChars as $column => $recognizedChar) {
            $current[] = min(
                $current[$column] + 1,
                $previous[$column + 1] + 1,
                $previous[$column] + ($referenceChar === $recognizedChar ? 0 : 1)
            );
        }
        $previous = $current;
    }

    return (float)$previous[count($recognizedChars)] / $referenceCount;
}

function hub_voice_transcript_validation(?string $expectedText, string $whisperRawText): array
{
    $transcriptNormalized = hub_voice_transcript_normalize($whisperRawText);
    $transcript = ['raw' => $whisperRawText, 'normalized' => $transcriptNormalized];
    if ($expectedText === null) {
        return [
            'normalizer' => HUB_VOICE_TRANSCRIPT_NORMALIZER_VERSION,
            'transcript' => $transcript,
            'expected_text' => null,
            'validation' => ['cer' => null, 'status' => 'unverified', 'needs_confirmation' => true],
        ];
    }
    if ($expectedText === '') {
        throw new InvalidArgumentException('voice_profile_transcript_invalid');
    }
    $expectedNormalized = hub_voice_transcript_normalize($expectedText);
    if ($expectedNormalized === '') {
        throw new InvalidArgumentException('voice_profile_transcript_invalid');
    }
    $cer = hub_voice_transcript_cer($expectedNormalized, $transcriptNormalized);
    $status = $cer === 0.0 ? 'clean' : ($cer <= 0.05 ? 'pass' : 'review_required');

    return [
        'normalizer' => HUB_VOICE_TRANSCRIPT_NORMALIZER_VERSION,
        'transcript' => $transcript,
        'expected_text' => ['raw' => $expectedText, 'normalized' => $expectedNormalized],
        'validation' => ['cer' => $cer, 'status' => $status, 'needs_confirmation' => $status === 'review_required'],
    ];
}

function hub_voice_profile_validation_json(?string $expectedText, ?string $whisperRawText): ?string
{
    if ($expectedText === null && $whisperRawText === null) {
        return null;
    }
    if ($whisperRawText === null) {
        $expectedNormalized = $expectedText === null ? null : hub_voice_transcript_normalize($expectedText);
        if ($expectedText !== null && $expectedNormalized === '') {
            throw new InvalidArgumentException('voice_profile_transcript_invalid');
        }
        $validation = [
            'normalizer' => HUB_VOICE_TRANSCRIPT_NORMALIZER_VERSION,
            'transcript' => null,
            'expected_text' => $expectedText === null ? null : ['raw' => $expectedText, 'normalized' => $expectedNormalized],
            'validation' => ['cer' => null, 'status' => 'unverified', 'needs_confirmation' => true],
        ];
    } else {
        $validation = hub_voice_transcript_validation($expectedText, $whisperRawText);
    }
    $json = json_encode($validation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return $json;
}

function hub_normalize_voice_profile_ref(string|int $value): int
{
    $value = trim((string)$value);
    if (preg_match('/^(?:voice_profile_|voice_asset_)?([1-9][0-9]*)$/', $value, $matches) !== 1) {
        throw new InvalidArgumentException('voice_profile_required');
    }

    return (int)$matches[1];
}

function hub_voice_profile_safe_host_path(string $path): ?string
{
    $storageDir = hub_voice_profile_storage_dir();
    $root = realpath($storageDir);
    if ($root === false || !is_dir($root) || is_link($storageDir) || $path === '') {
        return null;
    }

    $normalizedRoot = $root;
    $normalizedPath = $path;
    if (PHP_OS_FAMILY === 'Windows') {
        $normalizedRoot = str_replace('/', DIRECTORY_SEPARATOR, $normalizedRoot);
        $normalizedPath = str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        if (!str_starts_with(strtolower($normalizedPath), strtolower($normalizedRoot . DIRECTORY_SEPARATOR))) {
            return null;
        }
    } elseif (!str_starts_with($normalizedPath, $normalizedRoot . DIRECTORY_SEPARATOR)) {
        return null;
    }

    $candidate = $root;
    $parts = explode(DIRECTORY_SEPARATOR, substr($normalizedPath, strlen($normalizedRoot) + 1));
    foreach ($parts as $index => $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return null;
        }
        $candidate .= DIRECTORY_SEPARATOR . $part;
        clearstatcache(true, $candidate);
        if (is_link($candidate)) {
            return null;
        }
        if ($index < count($parts) - 1 && !is_dir($candidate)) {
            return null;
        }
    }
    if (!is_file($candidate)) {
        return null;
    }
    $stat = lstat($candidate);
    $real = realpath($candidate);
    if (
        $real === false
        || !is_array($stat)
        || (((int)$stat['mode'] & 0170000) !== 0100000)
        || (int)($stat['nlink'] ?? 0) !== 1
    ) {
        return null;
    }

    return str_starts_with($real, $root . DIRECTORY_SEPARATOR) ? $real : null;
}

function hub_voice_profile_file_stats_match(mixed $openedStat, mixed $pathStat, bool $requireSingleLink = true): bool
{
    return is_array($openedStat)
        && is_array($pathStat)
        && (((int)($openedStat['mode'] ?? 0) & 0170000) === 0100000)
        && (((int)($pathStat['mode'] ?? 0) & 0170000) === 0100000)
        && (int)($openedStat['nlink'] ?? 0) >= 1
        && (int)($pathStat['nlink'] ?? 0) >= 1
        && (!$requireSingleLink
            || ((int)$openedStat['nlink'] === 1 && (int)$pathStat['nlink'] === 1))
        && (int)($openedStat['dev'] ?? -1) === (int)($pathStat['dev'] ?? -2)
        && (int)($openedStat['ino'] ?? -1) === (int)($pathStat['ino'] ?? -2);
}

function hub_voice_profile_scrub_and_unlink(mixed $stream, string $path, ?callable $beforeDispose = null): bool
{
    if (!is_resource($stream) || !hub_voice_profile_file_stats_match(fstat($stream), @lstat($path))) {
        return false;
    }
    if ($beforeDispose !== null) {
        $beforeDispose($path);
    }
    clearstatcache(true, $path);
    if (!hub_voice_profile_file_stats_match(fstat($stream), @lstat($path), false)) {
        return false;
    }
    if (
        !@ftruncate($stream, 0)
        || !@fflush($stream)
        || !function_exists('fsync')
        || !@fsync($stream)
    ) {
        return false;
    }
    clearstatcache(true, $path);
    if (!hub_voice_profile_file_stats_match(fstat($stream), @lstat($path), false)) {
        return false;
    }
    $unlinked = @unlink($path);
    $after = fstat($stream);

    return $unlinked && is_array($after) && (int)($after['size'] ?? -1) === 0;
}

function hub_voice_profile_snapshot_dir(): string
{
    $root = realpath(hub_voice_profile_storage_dir());
    if ($root === false) {
        throw new RuntimeException('voice_profile_snapshot_storage_failed');
    }
    $dir = $root . DIRECTORY_SEPARATOR . '.snapshots';
    clearstatcache(true, $dir);
    if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700))) {
        throw new RuntimeException('voice_profile_snapshot_storage_failed');
    }
    @chmod($dir, 0700);
    $stat = @lstat($dir);
    $real = realpath($dir);
    $isSafeDirectory = $real !== false
        && hub_storage_paths_equal($real, $dir)
        && is_array($stat)
        && (((int)($stat['mode'] ?? 0) & 0170000) === 0040000);
    if (PHP_OS_FAMILY !== 'Windows') {
        $isSafeDirectory = $isSafeDirectory && (((int)$stat['mode'] & 0777) === 0700);
    }
    if (!$isSafeDirectory) {
        throw new RuntimeException('voice_profile_snapshot_storage_failed');
    }

    return $dir;
}

function hub_cleanup_stale_voice_profile_snapshots(?int $now = null): array
{
    $dir = hub_voice_profile_snapshot_dir();
    $now ??= time();
    $purged = 0;
    $count = 0;
    $bytes = 0;
    $scanned = 0;
    $overflow = false;
    foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
        if (++$scanned > 64) {
            $overflow = true;
            break;
        }
        $name = $entry->getFilename();
        if (preg_match('/^voice_profile_snapshot_[a-f0-9]{32}\.wav$/', $name) !== 1) {
            continue;
        }
        $path = $dir . '/' . $name;
        $stat = @lstat($path);
        if (!is_array($stat)) {
            continue;
        }
        $mode = (int)($stat['mode'] ?? 0) & 0170000;
        $stale = (int)($stat['mtime'] ?? $now) <= $now - 3600;
        if ($stale && $purged < 32) {
            if ($mode === 0120000 && @unlink($path)) {
                $purged++;
                continue;
            }
            if ($mode === 0100000 && (int)($stat['nlink'] ?? 0) === 1) {
                $stream = @fopen($path, 'r+b');
                $disposed = is_resource($stream)
                    && hub_voice_profile_scrub_and_unlink($stream, $path);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if ($disposed) {
                    $purged++;
                    continue;
                }
            }
        }
        $count++;
        if ($mode === 0100000) {
            $bytes += max(0, (int)($stat['size'] ?? 0));
        }
    }

    return [
        'purged' => $purged,
        'count' => $count,
        'bytes' => $bytes,
        'overflow' => $overflow,
    ];
}

function hub_voice_profile_verified_upload(string $rawPath, string $expectedSha256): ?array
{
    $path = hub_voice_profile_safe_host_path($rawPath);
    if ($path === null || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
        return null;
    }
    $source = @fopen($path, 'rb');
    $sourceStat = is_resource($source) ? fstat($source) : false;
    $sourceSize = is_array($sourceStat) ? (int)($sourceStat['size'] ?? 0) : 0;
    if (
        $source === false
        || !hub_voice_profile_file_stats_match($sourceStat, @lstat($path))
        || $sourceSize < 1
        || $sourceSize > 100 * 1024 * 1024
    ) {
        if (is_resource($source)) {
            fclose($source);
        }
        return null;
    }
    try {
        $snapshotState = hub_cleanup_stale_voice_profile_snapshots();
        $snapshotDir = hub_voice_profile_snapshot_dir();
    } catch (Throwable) {
        fclose($source);
        return null;
    }
    if (
        !empty($snapshotState['overflow'])
        || (int)$snapshotState['count'] >= 32
        || (int)$snapshotState['bytes'] > 256 * 1024 * 1024 - $sourceSize
    ) {
        fclose($source);
        return null;
    }
    $snapshotPath = $snapshotDir . '/voice_profile_snapshot_' . bin2hex(random_bytes(16)) . '.wav';
    $snapshot = @fopen($snapshotPath, 'x+b');
    $verified = false;
    try {
        if (
            $snapshot === false
            || !@chmod($snapshotPath, 0600)
            || stream_copy_to_stream($source, $snapshot) !== $sourceSize
            || !fflush($snapshot)
            || (function_exists('fsync') && !fsync($snapshot))
        ) {
            return null;
        }
        clearstatcache(true, $path);
        if (!hub_voice_profile_file_stats_match(fstat($source), @lstat($path))) {
            return null;
        }
        if (!rewind($snapshot)) {
            return null;
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $snapshot);
        $sha256 = hash_final($hash);
        clearstatcache(true, $snapshotPath);
        $snapshotStat = @lstat($snapshotPath);
        if (
            !hub_voice_profile_file_stats_match(fstat($snapshot), $snapshotStat)
            || !is_array($snapshotStat)
            || (((int)$snapshotStat['mode'] & 0777) !== 0600)
            || !hash_equals($expectedSha256, $sha256)
        ) {
            return null;
        }
        $verified = true;

        return [
            'tmp_name' => $snapshotPath,
            'type' => 'audio/wav',
            'size' => $sourceSize,
            'error' => UPLOAD_ERR_OK,
        ];
    } finally {
        fclose($source);
        if (is_resource($snapshot)) {
            fclose($snapshot);
        }
        if (!$verified) {
            @unlink($snapshotPath);
            @rmdir($snapshotDir);
        }
    }
}

function hub_voice_profile_container_path(array $profile): string
{
    $root = realpath(hub_voice_profile_storage_dir());
    $real = hub_voice_profile_safe_host_path((string)$profile['reference_audio_path']);
    if ($root === false || $real === null) {
        throw new RuntimeException('Invalid voice profile audio path.');
    }

    $relative = ltrim(substr($real, strlen($root)), DIRECTORY_SEPARATOR);
    return '/data/voice_profiles/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
}

function hub_gpt_sovits_reference_cut_seconds(string $path): float
{
    $result = hub_run_command([
        'ffmpeg', '-nostdin', '-hide_banner', '-i', $path,
        '-af', 'silencedetect=noise=-45dB:d=0.05', '-f', 'null', '-',
    ], 60);
    if ((int)($result['exit_code'] ?? 1) !== 0) {
        return 5.0;
    }
    preg_match_all('/silence_start:\s*([0-9]+(?:\.[0-9]+)?)/', (string)($result['output'] ?? ''), $matches);
    $cut = 5.0;
    foreach ($matches[1] ?? [] as $candidate) {
        $seconds = (float)$candidate;
        if ($seconds >= 3.0 && $seconds <= 7.0 && abs($seconds - 5.0) < abs($cut - 5.0)) {
            $cut = $seconds;
        }
    }

    return $cut;
}

function hub_voice_profile_is_gpt_sovits_reference_wav(string $path): bool
{
    $path = hub_voice_profile_safe_host_path($path);
    if ($path === null) {
        return false;
    }
    $probe = hub_run_command([
        'ffprobe', '-v', 'error', '-select_streams', 'a:0',
        '-show_entries', 'format=duration:stream=codec_type,sample_rate,channels', '-of', 'json', $path,
    ], 30);
    $decoded = (int)($probe['exit_code'] ?? 1) === 0
        ? json_decode((string)($probe['stdout'] ?? ''), true)
        : null;
    $stream = is_array($decoded['streams'] ?? null) ? ($decoded['streams'][0] ?? null) : null;
    $duration = is_array($decoded['format'] ?? null) ? (float)($decoded['format']['duration'] ?? 0) : 0.0;

    return is_array($stream)
        && ($stream['codec_type'] ?? '') === 'audio'
        && (int)($stream['sample_rate'] ?? 0) === 32000
        && (int)($stream['channels'] ?? 0) === 1
        && $duration >= 3.0
        && $duration <= 10.0;
}

function hub_normalize_gpt_sovits_reference(string $sourcePath, string $stagePath): void
{
    $sourcePath = hub_voice_profile_safe_host_path($sourcePath);
    $root = realpath(hub_voice_profile_storage_dir());
    if (
        $sourcePath === null
        || $root === false
        || !hub_storage_paths_equal(dirname($stagePath), $root)
        || preg_match('/^voice_profile_stage_[1-9][0-9]*_[a-f0-9]{32}\.wav$/', basename($stagePath)) !== 1
        || file_exists($stagePath)
        || is_link($stagePath)
    ) {
        throw new RuntimeException('voice_profile_reference_invalid');
    }
    $result = hub_run_command([
        'ffmpeg', '-nostdin', '-loglevel', 'error', '-y', '-i', $sourcePath,
        '-map', '0:a:0', '-ac', '1', '-ar', '32000', '-t', number_format(hub_gpt_sovits_reference_cut_seconds($sourcePath), 3, '.', ''),
        $stagePath,
    ], 60);
    if (
        (int)($result['exit_code'] ?? 1) !== 0
        || !@chmod($stagePath, 0600)
        || !hub_voice_profile_is_gpt_sovits_reference_wav($stagePath)
    ) {
        @unlink($stagePath);
        throw new RuntimeException('voice_profile_reference_invalid');
    }
}

function hub_promote_gpt_sovits_reference(PDO $db, array $task, array $profile): array
{
    $taskId = (int)($task['id'] ?? 0);
    $memberId = (int)($task['owner_member_id'] ?? 0);
    $profileId = (int)($profile['id'] ?? 0);
    $rawPath = (string)($profile['reference_audio_path'] ?? '');
    $rawSha256 = (string)($profile['reference_audio_sha256'] ?? '');
    if (
        $taskId < 1
        || $memberId < 1
        || $profileId < 1
        || (int)($profile['source_task_id'] ?? 0) !== $taskId
        || ($profile['reference_contract'] ?? 'generic') !== 'generic'
        || preg_match('/^[a-f0-9]{64}$/', $rawSha256) !== 1
    ) {
        throw new RuntimeException('voice_profile_reference_invalid');
    }
    $rawPath = hub_voice_profile_safe_host_path($rawPath);
    if ($rawPath === null) {
        throw new RuntimeException('voice_profile_reference_invalid');
    }
    $snapshot = hub_voice_profile_verified_upload($rawPath, $rawSha256);
    if ($snapshot === null) {
        throw new RuntimeException('voice_profile_reference_invalid');
    }
    $root = hub_voice_profile_storage_dir();
    $stagePath = $root . DIRECTORY_SEPARATOR . 'voice_profile_stage_' . $memberId . '_' . bin2hex(random_bytes(16)) . '.wav';
    $derivedPath = null;
    $transactionStarted = false;
    $promoted = false;
    try {
        hub_normalize_gpt_sovits_reference((string)$snapshot['tmp_name'], $stagePath);
        $derivedPath = $root . DIRECTORY_SEPARATOR . 'voice_profile_' . $memberId . '_' . bin2hex(random_bytes(16)) . '.wav';
        if (file_exists($derivedPath) || is_link($derivedPath) || !rename($stagePath, $derivedPath)) {
            throw new RuntimeException('voice_profile_reference_invalid');
        }
        $derivedSha256 = hash_file('sha256', $derivedPath);
        if (!is_string($derivedSha256) || !hub_voice_profile_is_gpt_sovits_reference_wav($derivedPath)) {
            throw new RuntimeException('voice_profile_reference_invalid');
        }

        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $current = hub_get_voice_profile($db, $profileId);
        if (
            $current === null
            || (int)($current['owner_member_id'] ?? 0) !== $memberId
            || (int)($current['source_task_id'] ?? 0) !== $taskId
            || (string)($current['reference_audio_path'] ?? '') !== $rawPath
            || !hash_equals($rawSha256, (string)($current['reference_audio_sha256'] ?? ''))
            || ($current['reference_contract'] ?? 'generic') !== 'generic'
        ) {
            throw new RuntimeException('voice_profile_reference_changed');
        }
        $update = $db->prepare(
            "UPDATE voice_profiles
             SET reference_audio_path = :reference_audio_path,
                 reference_audio_sha256 = :reference_audio_sha256,
                 reference_contract = 'gpt_sovits_v1', updated_at = :updated_at
             WHERE id = :id AND owner_member_id = :owner_member_id AND source_task_id = :source_task_id
               AND reference_audio_path = :old_reference_audio_path
               AND reference_audio_sha256 = :old_reference_audio_sha256
               AND reference_contract = 'generic'"
        );
        $update->execute([
            ':reference_audio_path' => $derivedPath,
            ':reference_audio_sha256' => $derivedSha256,
            ':updated_at' => hub_now(),
            ':id' => $profileId,
            ':owner_member_id' => $memberId,
            ':source_task_id' => $taskId,
            ':old_reference_audio_path' => $rawPath,
            ':old_reference_audio_sha256' => $rawSha256,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('voice_profile_reference_changed');
        }
        hub_record_voice_profile_audit($db, $profileId, $memberId, null, 'prepare_reference', 'voice_generate_gpt_sovits', [
            'reference_contract' => 'gpt_sovits_v1',
        ]);
        $db->exec('COMMIT');
        $transactionStarted = false;
        $promoted = true;

        $raw = @fopen($rawPath, 'r+b');
        $rawHash = is_resource($raw) ? hash_init('sha256') : null;
        if (
            !is_resource($raw)
            || !hub_voice_profile_file_stats_match(fstat($raw), @lstat($rawPath))
            || $rawHash === null
            || !hash_update_stream($rawHash, $raw)
            || !hash_equals($rawSha256, hash_final($rawHash))
            || !hub_voice_profile_scrub_and_unlink($raw, $rawPath)
        ) {
            throw new RuntimeException('voice_profile_reference_cleanup_failed');
        }
        fclose($raw);

        return hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        if (isset($raw) && is_resource($raw)) {
            fclose($raw);
        }
        if (!$promoted && is_string($derivedPath) && is_file($derivedPath)) {
            @unlink($derivedPath);
        }
        if (is_file($stagePath)) {
            @unlink($stagePath);
        }
        throw $e;
    } finally {
        @unlink((string)$snapshot['tmp_name']);
        @rmdir(dirname((string)$snapshot['tmp_name']));
    }
}

function hub_find_active_voice_profile_by_owner_sha(PDO $db, int $ownerMemberId, string $sha256): ?array
{
    $sha256 = strtolower(trim($sha256));
    if ($ownerMemberId < 1 || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT * FROM voice_profiles
         WHERE owner_member_id = :owner_member_id
           AND reference_audio_sha256 = :reference_audio_sha256
           AND deleted_at IS NULL
           AND (expires_at IS NULL OR expires_at > :now)
         LIMIT 1'
    );
    $stmt->execute([
        ':owner_member_id' => $ownerMemberId,
        ':reference_audio_sha256' => $sha256,
        ':now' => hub_now(),
    ]);
    $profile = $stmt->fetch();

    return $profile ?: null;
}

function hub_validate_voice_profile_wav(array $upload): array
{
    if (!isset($upload['error']) || !is_int($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('voice_profile_upload_failed');
    }
    $tmpName = $upload['tmp_name'] ?? null;
    if (!is_string($tmpName) || trim($tmpName) === '' || !is_file($tmpName)) {
        throw new InvalidArgumentException('voice_profile_file_required');
    }

    $size = filesize($tmpName);
    if ($size === false || $size < 1 || $size > 100 * 1024 * 1024) {
        throw new InvalidArgumentException('voice_profile_wav_size_invalid');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    if (!in_array($mime, ['audio/wav', 'audio/x-wav', 'audio/wave'], true)) {
        throw new InvalidArgumentException('voice_profile_wav_invalid');
    }
    $header = file_get_contents($tmpName, false, null, 0, 12);
    if ($header === false || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
        throw new InvalidArgumentException('voice_profile_wav_invalid');
    }
    $sha256 = hash_file('sha256', $tmpName);
    if ($sha256 === false) {
        throw new RuntimeException('voice_profile_hash_failed');
    }

    return ['tmp_name' => $tmpName, 'mime' => $mime, 'size' => $size, 'sha256' => $sha256];
}

function hub_voice_profile_transcription_error_code(mixed $error): string
{
    $error = trim((string)$error);
    return in_array($error, ['asr_unavailable', 'transcript_validation_failed'], true) ? $error : 'asr_failed';
}

function hub_voice_profile_transcription_lease_seconds(PDO $db): int
{
    $service = hub_get_service_by_mode($db, 'asr');
    // ponytail: timeout+30s lease (300s fallback) avoids infinite pending; durable worker leases can replace it later.
    return $service && trim((string)($service['internal_url'] ?? '')) !== ''
        ? hub_service_gateway_timeout_sec($service) + 30
        : 300;
}

function hub_voice_profile_transcription_is_stale(PDO $db, array $profile): bool
{
    $startedAt = strtotime((string)($profile['transcription_started_at'] ?? ''));

    return $startedAt === false || time() - $startedAt >= hub_voice_profile_transcription_lease_seconds($db);
}

function hub_claim_voice_profile_transcription(PDO $db, int $profileId): array
{
    $now = hub_now();
    $leaseToken = bin2hex(random_bytes(32));
    $stmt = $db->prepare('UPDATE voice_profiles SET transcription_status = :transcription_status, transcription_error = NULL, transcription_started_at = :transcription_started_at, transcription_lease_token = :transcription_lease_token, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([
        ':transcription_status' => 'pending',
        ':transcription_started_at' => $now,
        ':transcription_lease_token' => $leaseToken,
        ':updated_at' => $now,
        ':id' => $profileId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('voice_profile_missing');
    }

    return hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing');
}

function hub_voice_profile_lost_lease_response(PDO $db, int $profileId, array $profile): array
{
    return [
        'profile' => hub_get_voice_profile($db, $profileId) ?? $profile,
        'cache_hit' => false,
        'transcription' => [
            'ok' => false,
            'error' => 'transcription_lost_lease',
            'message' => 'Transcription result was superseded',
        ],
    ];
}

function hub_voice_profile_save_failure_response(PDO $db, int $profileId, array $profile): array
{
    return [
        'profile' => hub_get_voice_profile($db, $profileId) ?? $profile,
        'cache_hit' => false,
        'transcription' => [
            'ok' => false,
            'error' => 'transcription_save_failed',
            'message' => 'Transcription result could not be saved',
        ],
    ];
}

function hub_finalize_voice_profile_transcription(PDO $db, int $profileId, int $ownerMemberId, string $sql, array $parameters, array $auditDetails): string
{
    $transactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $stmt = $db->prepare($sql);
        $stmt->execute($parameters);
        if ($stmt->rowCount() !== 1) {
            $db->exec('COMMIT');
            $transactionStarted = false;
            return 'lost';
        }
        hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'transcribe', null, $auditDetails);
        $db->exec('COMMIT');
        $transactionStarted = false;

        return 'applied';
    } catch (Throwable) {
        if ($transactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }

        return 'error';
    }
}

function hub_cleanup_stale_voice_profile_staging(PDO $db): void
{
    $leaseSeconds = hub_voice_profile_transcription_lease_seconds($db);
    $dir = hub_voice_profile_storage_dir();
    foreach (glob($dir . '/voice_profile_stage_*.wav') ?: [] as $path) {
        if (
            preg_match('/^voice_profile_stage_[1-9][0-9]*_[a-f0-9]{32}\.wav$/', basename($path)) !== 1
            || !is_file($path)
        ) {
            continue;
        }
        $modifiedAt = filemtime($path);
        if ($modifiedAt !== false && time() - $modifiedAt >= $leaseSeconds && !unlink($path)) {
            throw new RuntimeException('voice_profile_staging_cleanup_failed');
        }
    }
}

function hub_cleanup_stale_voice_profile_finals(PDO $db): void
{
    $leaseSeconds = hub_voice_profile_transcription_lease_seconds($db);
    $dir = hub_voice_profile_storage_dir();
    $referenceStmt = $db->prepare('SELECT 1 FROM voice_profiles WHERE reference_audio_path = :reference_audio_path LIMIT 1');
    foreach (glob($dir . '/voice_profile_*.wav') ?: [] as $path) {
        if (
            preg_match('/^voice_profile_[1-9][0-9]*_[a-f0-9]{32}\.wav$/', basename($path)) !== 1
            || !is_file($path)
        ) {
            continue;
        }
        $modifiedAt = filemtime($path);
        if ($modifiedAt === false || time() - $modifiedAt < $leaseSeconds) {
            continue;
        }
        $referenceStmt->execute([':reference_audio_path' => $path]);
        if ($referenceStmt->fetchColumn() !== false) {
            continue;
        }
        if (!unlink($path)) {
            throw new RuntimeException('voice_profile_final_cleanup_failed');
        }
    }
}

function hub_voice_profile_pending_response(array $profile): array
{
    return [
        'profile' => $profile,
        'cache_hit' => false,
        'transcription' => [
            'ok' => false,
            'error' => 'transcription_pending',
            'message' => 'Transcription is pending',
        ],
    ];
}

function hub_run_voice_profile_transcription(PDO $db, array $profile, int $ownerMemberId, ?callable $transcribe = null): array
{
    $profileId = (int)($profile['id'] ?? 0);
    $leaseToken = trim((string)($profile['transcription_lease_token'] ?? ''));
    if ($profileId < 1 || $leaseToken === '') {
        return hub_voice_profile_lost_lease_response($db, $profileId, $profile);
    }
    $verifiedUpload = hub_voice_profile_verified_upload(
        (string)($profile['reference_audio_path'] ?? ''),
        (string)($profile['reference_audio_sha256'] ?? '')
    );
    if ($verifiedUpload === null) {
        $transcription = ['ok' => false, 'error' => 'asr_failed'];
    } else {
        try {
            $transcription = $transcribe === null
                ? hub_transcribe_voice_profile($db, $verifiedUpload)
                : $transcribe($verifiedUpload);
        } catch (Throwable) {
            $transcription = ['ok' => false, 'error' => 'asr_failed'];
        } finally {
            @unlink((string)$verifiedUpload['tmp_name']);
            @rmdir(dirname((string)$verifiedUpload['tmp_name']));
        }
    }
    if (!is_array($transcription) || empty($transcription['ok'])) {
        $error = hub_voice_profile_transcription_error_code($transcription['error'] ?? null);
        $failure = [
            'ok' => false,
            'error' => $error,
            'message' => $error === 'asr_unavailable' ? 'ASR service is unavailable' : 'ASR transcription failed',
        ];
        $finalization = hub_finalize_voice_profile_transcription(
            $db,
            $profileId,
            $ownerMemberId,
            "UPDATE voice_profiles SET transcription_status = :transcription_status, transcription_error = :transcription_error, transcription_started_at = NULL, transcription_lease_token = NULL, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL AND transcription_status = 'pending' AND transcription_lease_token = :transcription_lease_token",
            [
                ':transcription_status' => 'failed',
                ':transcription_error' => $error,
                ':updated_at' => hub_now(),
                ':id' => $profileId,
                ':transcription_lease_token' => $leaseToken,
            ],
            ['status' => 'failed', 'error' => $error]
        );
        if ($finalization === 'lost') {
            return hub_voice_profile_lost_lease_response($db, $profileId, $profile);
        }
        if ($finalization !== 'applied') {
            return hub_voice_profile_save_failure_response($db, $profileId, $profile);
        }

        return [
            'profile' => hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing'),
            'cache_hit' => false,
            'transcription' => $failure,
        ];
    }

    $rawText = (string)($transcription['whisper_raw_text'] ?? $transcription['raw_text'] ?? $transcription['text'] ?? '');
    $text = trim($rawText);
    $language = trim((string)($transcription['language'] ?? '')) ?: 'auto';
    $device = is_array($transcription['device'] ?? null) ? $transcription['device'] : [];
    $validationJson = null;
    try {
        $storedValidation = json_decode((string)($profile['transcript_validation_json'] ?? ''), true);
        $storedExpected = is_array($storedValidation) && is_array($storedValidation['expected_text'] ?? null)
            ? (string)($storedValidation['expected_text']['raw'] ?? '')
            : '';
        $expectedText = $storedExpected === '' ? null : $storedExpected;
        $validationJson = hub_voice_profile_validation_json($expectedText, $rawText);
    } catch (Throwable) {
        $validationJson = json_encode([
            'normalizer' => HUB_VOICE_TRANSCRIPT_NORMALIZER_VERSION,
            'transcript' => ['raw' => $rawText, 'normalized' => null],
            'expected_text' => isset($expectedText) && $expectedText !== null ? ['raw' => $expectedText, 'normalized' => null] : null,
            'validation' => ['cer' => null, 'status' => 'error', 'needs_confirmation' => true, 'error' => 'transcript_validation_failed'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $error = 'transcript_validation_failed';
        $finalization = hub_finalize_voice_profile_transcription(
            $db,
            $profileId,
            $ownerMemberId,
            "UPDATE voice_profiles SET prompt_text = :prompt_text, language = :language, prompt_text_confirmed_at = NULL, transcription_status = 'failed', transcription_error = :transcription_error, transcript_validation_json = :transcript_validation_json, transcription_started_at = NULL, transcription_lease_token = NULL, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL AND transcription_status = 'pending' AND transcription_lease_token = :transcription_lease_token",
            [
                ':prompt_text' => $text,
                ':language' => $language,
                ':transcription_error' => $error,
                ':transcript_validation_json' => $validationJson,
                ':updated_at' => hub_now(),
                ':id' => $profileId,
                ':transcription_lease_token' => $leaseToken,
            ],
            ['status' => 'validation_error', 'error' => $error]
        );
        if ($finalization === 'lost') {
            return hub_voice_profile_lost_lease_response($db, $profileId, $profile);
        }
        if ($finalization !== 'applied') {
            return hub_voice_profile_save_failure_response($db, $profileId, $profile);
        }

        return [
            'profile' => hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing'),
            'cache_hit' => false,
            'transcription' => ['ok' => false, 'error' => $error, 'message' => 'Transcript validation failed'],
        ];
    }
    $finalization = hub_finalize_voice_profile_transcription(
        $db,
        $profileId,
        $ownerMemberId,
        "UPDATE voice_profiles SET prompt_text = :prompt_text, language = :language, prompt_text_confirmed_at = NULL, transcription_status = :transcription_status, transcription_error = NULL, transcript_validation_json = :transcript_validation_json, transcription_started_at = NULL, transcription_lease_token = NULL, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL AND transcription_status = 'pending' AND transcription_lease_token = :transcription_lease_token",
        [
            ':prompt_text' => $text,
            ':language' => $language,
            ':transcription_status' => 'ready',
            ':transcript_validation_json' => $validationJson,
            ':updated_at' => hub_now(),
            ':id' => $profileId,
            ':transcription_lease_token' => $leaseToken,
        ],
        [
            'status' => 'success',
            'device' => $device,
            'text_chars' => function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text),
        ]
    );
    if ($finalization === 'lost') {
        return hub_voice_profile_lost_lease_response($db, $profileId, $profile);
    }
    if ($finalization !== 'applied') {
        return hub_voice_profile_save_failure_response($db, $profileId, $profile);
    }

    return [
        'profile' => hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing'),
        'cache_hit' => false,
        'transcription' => ['ok' => true, 'text' => $text, 'whisper_raw_text' => $rawText, 'language' => $language, 'device' => $device, 'validation' => json_decode((string)$validationJson, true)],
    ];
}

function hub_retry_voice_profile_transcription(PDO $db, int $profileId, int $ownerMemberId, ?callable $transcribe = null): array
{
    $profile = null;
    $retryTransactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $retryTransactionStarted = true;
        $profile = hub_get_voice_profile($db, $profileId);
        if ($profile === null || (int)$profile['owner_member_id'] !== $ownerMemberId) {
            throw new InvalidArgumentException('voice_profile_forbidden');
        }
        $status = (string)($profile['transcription_status'] ?? 'pending');
        if ($status === 'ready') {
            throw new InvalidArgumentException('voice_profile_transcription_not_retryable');
        }
        if ($status === 'pending' && !hub_voice_profile_transcription_is_stale($db, $profile)) {
            $db->exec('COMMIT');
            $retryTransactionStarted = false;
            return hub_voice_profile_pending_response($profile);
        }
        if ($status !== 'failed' && $status !== 'pending') {
            throw new InvalidArgumentException('voice_profile_transcription_not_retryable');
        }
        $profile = hub_claim_voice_profile_transcription($db, $profileId);
        $db->exec('COMMIT');
        $retryTransactionStarted = false;
    } catch (Throwable $e) {
        if ($retryTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }

    return hub_run_voice_profile_transcription($db, $profile, $ownerMemberId, $transcribe);
}

function hub_apply_cached_voice_profile_draft(PDO $db, array $profile, int $ownerMemberId, string $promptText, ?string $language, bool $transcriptConfirmed): array
{
    $cachedDraftTransactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $cachedDraftTransactionStarted = true;
        $result = hub_apply_cached_voice_profile_draft_in_transaction(
            $db,
            $profile,
            $ownerMemberId,
            $promptText,
            $language,
            $transcriptConfirmed
        );
        $db->exec('COMMIT');
        $cachedDraftTransactionStarted = false;
        return $result;
    } catch (Throwable $e) {
        if ($cachedDraftTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }
}

function hub_apply_cached_voice_profile_draft_in_transaction(PDO $db, array $profile, int $ownerMemberId, string $promptText, ?string $language, bool $transcriptConfirmed): array
{
    $profileId = (int)($profile['id'] ?? 0);
    $promptText = trim($promptText);
    $language = trim((string)$language) ?: null;
    if ($profileId < 1 || $ownerMemberId < 1 || strlen($promptText) > 20000 || ($language !== null && strlen($language) > 64)) {
        throw new InvalidArgumentException('voice_profile_draft_invalid');
    }

    $stmt = $db->prepare('SELECT * FROM voice_profiles WHERE id = :id AND owner_member_id = :owner_member_id AND deleted_at IS NULL');
    $stmt->execute([':id' => $profileId, ':owner_member_id' => $ownerMemberId]);
    $profile = $stmt->fetch() ?: throw new RuntimeException('voice_profile_missing');
    $previousSourceTaskId = (int)($profile['source_task_id'] ?? 0);
    $existingConfirmed = trim((string)($profile['prompt_text_confirmed_at'] ?? '')) !== '';
    $changed = $promptText !== '' && (
        (string)($profile['prompt_text'] ?? '') !== $promptText
        || (trim((string)($profile['language'] ?? '')) ?: null) !== $language
        || (string)($profile['transcription_status'] ?? '') !== 'ready'
        || trim((string)($profile['transcription_error'] ?? '')) !== ''
        || trim((string)($profile['transcription_started_at'] ?? '')) !== ''
        || trim((string)($profile['transcription_lease_token'] ?? '')) !== ''
        || $existingConfirmed !== $transcriptConfirmed
    );
    $auditDetails = ['status' => 'reused'];
    if ($changed) {
        $now = hub_now();
        $update = $db->prepare(
            "UPDATE voice_profiles
             SET prompt_text = :prompt_text, language = :language, prompt_text_confirmed_at = NULL,
                 transcription_status = 'ready', transcription_error = NULL,
                 transcription_started_at = NULL, transcription_lease_token = NULL,
                 source_task_id = NULL, updated_at = :updated_at
             WHERE id = :id AND owner_member_id = :owner_member_id AND deleted_at IS NULL"
        );
        $update->execute([
            ':prompt_text' => $promptText,
            ':language' => $language,
            ':updated_at' => $now,
            ':id' => $profileId,
            ':owner_member_id' => $ownerMemberId,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('voice_profile_missing');
        }
        $profile = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing');
        $auditDetails = [
            'status' => 'ready',
            'text_chars' => function_exists('mb_strlen') ? mb_strlen($promptText, 'UTF-8') : strlen($promptText),
            'prompt_text_sha256' => hash('sha256', $promptText),
        ];
    }
    hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'cache_hit', null, $auditDetails);

    return [
        'profile' => $profile,
        'cache_hit' => true,
        'draft_changed' => $changed,
        'previous_source_task_id' => $changed ? $previousSourceTaskId : 0,
    ];
}

function hub_create_uploaded_voice_profile(PDO $db, int $ownerMemberId, array $upload, array $input, ?callable $moveFile = null, ?callable $transcribe = null, array $options = []): array
{
    return hub_create_uploaded_voice_profile_internal($db, $ownerMemberId, $upload, $input, $moveFile, $transcribe, $options, null);
}

function hub_create_uploaded_voice_profile_internal(PDO $db, int $ownerMemberId, array $upload, array $input, ?callable $moveFile, ?callable $transcribe, array $options, ?callable $transactionCallback): array
{
    if (array_diff(array_keys($options), ['defer_transcription', 'allow_cache']) !== []) {
        throw new InvalidArgumentException('voice_profile_options_invalid');
    }
    foreach ($options as $value) {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('voice_profile_options_invalid');
        }
    }
    $deferTranscription = $options['defer_transcription'] ?? false;
    $allowCache = $options['allow_cache'] ?? true;
    if (!hub_get_api_member($db, $ownerMemberId)) {
        throw new InvalidArgumentException('Member not found.');
    }
    $wav = hub_validate_voice_profile_wav($upload);
    $profileInput = hub_validate_voice_profile_input($input);
    $profile = $allowCache ? hub_find_active_voice_profile_by_owner_sha($db, $ownerMemberId, $wav['sha256']) : null;
    if ($profile !== null && $transactionCallback === null) {
        if ($deferTranscription) {
            $cachedDraft = hub_apply_cached_voice_profile_draft(
                $db,
                $profile,
                $ownerMemberId,
                (string)($input['prompt_text'] ?? ''),
                isset($input['language']) ? (string)$input['language'] : null,
                (bool)($input['transcript_confirmed'] ?? false)
            );
            return ['profile' => $cachedDraft['profile'], 'cache_hit' => true];
        }
        $status = (string)($profile['transcription_status'] ?? 'pending');
        if ($status === 'ready') {
            hub_record_voice_profile_audit($db, (int)$profile['id'], $ownerMemberId, null, 'cache_hit', null, ['status' => 'reused']);
            return ['profile' => $profile, 'cache_hit' => true];
        }
        if ($status === 'pending') {
            if (!hub_voice_profile_transcription_is_stale($db, $profile)) {
                hub_record_voice_profile_audit($db, (int)$profile['id'], $ownerMemberId, null, 'transcription_pending', null, ['status' => 'pending']);
                return hub_voice_profile_pending_response($profile);
            }

            return hub_retry_voice_profile_transcription($db, (int)$profile['id'], $ownerMemberId, $transcribe);
        }

        return hub_retry_voice_profile_transcription($db, (int)$profile['id'], $ownerMemberId, $transcribe);
    }
    hub_cleanup_stale_voice_profile_staging($db);
    $dir = hub_voice_profile_storage_dir();
    $stagingPath = $dir . DIRECTORY_SEPARATOR . 'voice_profile_stage_' . $ownerMemberId . '_' . bin2hex(random_bytes(16)) . '.wav';
    $path = null;
    $moveFile ??= static fn (string $from, string $to): bool => move_uploaded_file($from, $to);
    if (!$moveFile($wav['tmp_name'], $stagingPath) || !is_file($stagingPath)) {
        throw new RuntimeException('voice_profile_upload_failed');
    }

    $profile = null;
    $outcome = null;
    $finalized = false;
    $deferredCache = null;
    $profileWasCached = false;
    $voiceProfileUploadTransactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $voiceProfileUploadTransactionStarted = true;
        $profile = $allowCache ? hub_find_active_voice_profile_by_owner_sha($db, $ownerMemberId, $wav['sha256']) : null;
        if ($profile !== null) {
            $profileWasCached = true;
            if (!unlink($stagingPath)) {
                throw new RuntimeException('voice_profile_upload_failed');
            }
            if ($deferTranscription) {
                if (array_key_exists('expected_text', $input) && $input['expected_text'] !== null) {
                    $validationSeed = hub_voice_profile_validation_json((string)$input['expected_text'], null);
                    $seed = $db->prepare('UPDATE voice_profiles SET transcript_validation_json = :validation WHERE id = :id AND owner_member_id = :owner_member_id AND deleted_at IS NULL');
                    $seed->execute([
                        ':validation' => $validationSeed,
                        ':id' => (int)$profile['id'],
                        ':owner_member_id' => $ownerMemberId,
                    ]);
                    $profile = hub_get_voice_profile($db, (int)$profile['id']) ?? throw new RuntimeException('voice_profile_missing');
                }
                $deferredCache = hub_apply_cached_voice_profile_draft_in_transaction(
                    $db,
                    $profile,
                    $ownerMemberId,
                    (string)($input['prompt_text'] ?? ''),
                    isset($input['language']) ? (string)$input['language'] : null,
                    (bool)($input['transcript_confirmed'] ?? false)
                );
                $profile = $deferredCache['profile'];
                $outcome = 'deferred_cache';
            } else {
                $status = (string)($profile['transcription_status'] ?? 'pending');
                if ($status === 'ready') {
                    $outcome = 'cache_hit';
                } elseif ($status === 'failed') {
                    $profile = hub_claim_voice_profile_transcription($db, (int)$profile['id']);
                    $outcome = 'transcribe';
                } elseif (hub_voice_profile_transcription_is_stale($db, $profile)) {
                    $profile = hub_claim_voice_profile_transcription($db, (int)$profile['id']);
                    $outcome = 'transcribe';
                } else {
                    $outcome = 'pending';
                }
            }
        } else {
            hub_cleanup_stale_voice_profile_finals($db);
            $path = $dir . DIRECTORY_SEPARATOR . 'voice_profile_' . $ownerMemberId . '_' . bin2hex(random_bytes(16)) . '.wav';
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('voice_profile_upload_failed');
            }
            if (!rename($stagingPath, $path) || !is_file($path)) {
                throw new RuntimeException('voice_profile_upload_failed');
            }
            $finalized = true;
            $profileId = hub_create_voice_profile($db, $ownerMemberId, [
                'name' => $profileInput['name'],
                'reference_audio_path' => $path,
                'consent_type' => $profileInput['consent_type'],
                'usage_scope' => 'private',
                'visibility' => 'private',
                'retain_original_audio' => $input['retain_original_audio'] ?? 1,
                'prompt_text' => $deferTranscription ? ($input['prompt_text'] ?? null) : null,
                'transcript_validation_json' => $deferTranscription
                    ? hub_voice_profile_validation_json(isset($input['expected_text']) ? (string)$input['expected_text'] : null, null)
                    : null,
                'language' => $deferTranscription ? ($input['language'] ?? null) : null,
                'transcription_status' => $deferTranscription && trim((string)($input['prompt_text'] ?? '')) !== '' ? 'ready' : 'pending',
                'expires_at' => $input['expires_at'] ?? null,
            ]);
            $profile = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing');
            $outcome = $deferTranscription ? 'deferred' : 'transcribe';
        }
        if ($transactionCallback !== null) {
            $transactionCallback($deferredCache ?? [
                'profile' => $profile,
                'cache_hit' => $profileWasCached,
                'draft_changed' => false,
                'previous_source_task_id' => 0,
            ]);
            $profile = hub_get_voice_profile($db, (int)$profile['id']) ?? throw new RuntimeException('voice_profile_missing');
            if ($deferredCache !== null) {
                $deferredCache['profile'] = $profile;
            }
        }
        $db->exec('COMMIT');
        $voiceProfileUploadTransactionStarted = false;
    } catch (Throwable $e) {
        $cleanupFailed = false;
        if ($voiceProfileUploadTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
            $voiceProfileUploadTransactionStarted = false;
        }
        if ($finalized && $path !== null && is_file($path) && !unlink($path)) {
            $cleanupFailed = true;
        }
        if (is_file($stagingPath) && !unlink($stagingPath)) {
            $cleanupFailed = true;
        }
        if ($cleanupFailed) {
            throw new RuntimeException('voice_profile_upload_cleanup_failed', 0, $e);
        }
        throw $e;
    }
    if ($outcome === 'cache_hit') {
        hub_record_voice_profile_audit($db, (int)$profile['id'], $ownerMemberId, null, 'cache_hit', null, ['status' => 'reused']);
        return ['profile' => $profile, 'cache_hit' => true];
    }
    if ($outcome === 'deferred_cache') {
        if ($deferredCache === null) {
            throw new RuntimeException('voice_profile_missing');
        }
        return ['profile' => $deferredCache['profile'], 'cache_hit' => true];
    }
    if ($outcome === 'pending') {
        hub_record_voice_profile_audit($db, (int)$profile['id'], $ownerMemberId, null, 'transcription_pending', null, ['status' => 'pending']);
        return hub_voice_profile_pending_response($profile);
    }
    if ($outcome === 'deferred') {
        return ['profile' => $profile, 'cache_hit' => false];
    }

    return hub_run_voice_profile_transcription($db, $profile, $ownerMemberId, $transcribe);
}

function hub_transcribe_voice_profile(PDO $db, array $upload): array
{
    $service = hub_get_service_by_mode($db, 'asr');
    if (
        !$service
        || (int)($service['enabled'] ?? 0) !== 1
        || (string)($service['install_status'] ?? '') !== 'installed'
        || trim((string)($service['internal_url'] ?? '')) === ''
    ) {
        return ['ok' => false, 'error' => 'asr_unavailable', 'message' => 'ASR service is unavailable'];
    }

    $previousFiles = $_FILES;
    $previousPost = $_POST;
    $hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $previousRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    try {
        $_FILES = ['audio' => [
            'name' => 'voice-profile.wav',
            'type' => (string)($upload['type'] ?? 'audio/wav'),
            'tmp_name' => (string)($upload['tmp_name'] ?? ''),
            'error' => UPLOAD_ERR_OK,
            'size' => (int)($upload['size'] ?? 0),
        ]];
        $_POST = ['real_inference' => '1'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $response = hub_proxy_request((string)$service['internal_url'], hub_service_gateway_timeout_sec($service));
        $body = json_decode((string)($response['body'] ?? ''), true);
        if ((int)($response['status'] ?? 0) >= 200 && (int)($response['status'] ?? 0) < 300 && is_array($body) && !empty($body['ok'])) {
            return [
                'ok' => true,
                'text' => trim((string)($body['text'] ?? '')),
                'whisper_raw_text' => (string)($body['whisper_raw_text'] ?? $body['raw_text'] ?? $body['text'] ?? ''),
                'language' => (string)($body['language'] ?? 'auto'),
                'device' => is_array($body['device'] ?? null) ? $body['device'] : [],
            ];
        }

        return [
            'ok' => false,
            'error' => trim((string)($body['error'] ?? '')) ?: 'asr_failed',
            'message' => trim((string)($body['message'] ?? '')) ?: 'ASR transcription failed',
        ];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'asr_failed', 'message' => 'ASR transcription failed'];
    } finally {
        $_FILES = $previousFiles;
        $_POST = $previousPost;
        if ($hadRequestMethod) {
            $_SERVER['REQUEST_METHOD'] = $previousRequestMethod;
        } else {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }
}

function hub_valid_voice_profile_consent(string $value): string
{
    $value = trim($value);
    if (!in_array($value, ['self_recorded', 'explicit_permission', 'licensed_voice'], true)) {
        throw new InvalidArgumentException('consent_type must be self_recorded, explicit_permission or licensed_voice.');
    }

    return $value;
}

function hub_validate_voice_profile_input(array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Voice profile name is required.');
    }
    $consentType = hub_valid_voice_profile_consent((string)($input['consent_type'] ?? ''));
    $visibility = trim((string)($input['visibility'] ?? 'private'));
    if (!in_array($visibility, ['private', 'shared'], true)) {
        throw new InvalidArgumentException('Invalid visibility.');
    }
    $usageScope = trim((string)($input['usage_scope'] ?? 'private')) ?: 'private';
    if (!in_array($usageScope, ['private', 'internal', 'licensed'], true)) {
        throw new InvalidArgumentException('Invalid usage scope.');
    }

    return ['name' => $name, 'consent_type' => $consentType, 'visibility' => $visibility, 'usage_scope' => $usageScope];
}

function hub_create_voice_profile(PDO $db, int $ownerMemberId, array $input): int
{
    if (!hub_get_api_member($db, $ownerMemberId)) {
        throw new InvalidArgumentException('Member not found.');
    }
    $profileInput = hub_validate_voice_profile_input($input);
    $path = hub_voice_profile_safe_host_path((string)($input['reference_audio_path'] ?? ''));
    if ($path === null) {
        throw new InvalidArgumentException('reference audio must be a managed voice profile asset.');
    }

    $promptText = trim((string)($input['prompt_text'] ?? '')) ?: null;
    $transcriptValidationJson = isset($input['transcript_validation_json'])
        ? (string)$input['transcript_validation_json']
        : null;
    $transcriptionStatus = trim((string)($input['transcription_status'] ?? ''));
    if ($transcriptionStatus === '') {
        $transcriptionStatus = $promptText === null ? 'pending' : 'ready';
    }
    if (!in_array($transcriptionStatus, ['pending', 'ready', 'failed'], true)) {
        throw new InvalidArgumentException('Invalid transcription status.');
    }
    $transcriptionError = $transcriptionStatus === 'failed'
        ? hub_voice_profile_transcription_error_code($input['transcription_error'] ?? null)
        : null;
    $now = hub_now();
    $transcriptionStartedAt = $transcriptionStatus === 'pending' ? $now : null;
    $transcriptionLeaseToken = $transcriptionStatus === 'pending' ? bin2hex(random_bytes(32)) : null;
    $stmt = $db->prepare(
        'INSERT INTO voice_profiles
            (owner_member_id, name, reference_audio_path, reference_audio_sha256, prompt_text, transcript_validation_json, language,
             transcription_status, transcription_error, transcription_started_at, transcription_lease_token, consent_type, usage_scope, visibility, retain_original_audio, expires_at, created_at, updated_at)
         VALUES
            (:owner_member_id, :name, :reference_audio_path, :reference_audio_sha256, :prompt_text, :transcript_validation_json, :language,
             :transcription_status, :transcription_error, :transcription_started_at, :transcription_lease_token, :consent_type, :usage_scope, :visibility, :retain_original_audio, :expires_at, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':owner_member_id' => $ownerMemberId,
        ':name' => $profileInput['name'],
        ':reference_audio_path' => $path,
        ':reference_audio_sha256' => hash_file('sha256', $path),
        ':prompt_text' => $promptText,
        ':transcript_validation_json' => $transcriptValidationJson,
        ':language' => trim((string)($input['language'] ?? '')) ?: null,
        ':transcription_status' => $transcriptionStatus,
        ':transcription_error' => $transcriptionError,
        ':transcription_started_at' => $transcriptionStartedAt,
        ':transcription_lease_token' => $transcriptionLeaseToken,
        ':consent_type' => $profileInput['consent_type'],
        ':usage_scope' => $profileInput['usage_scope'],
        ':visibility' => $profileInput['visibility'],
        ':retain_original_audio' => !array_key_exists('retain_original_audio', $input) || (int)$input['retain_original_audio'] === 1 ? 1 : 0,
        ':expires_at' => trim((string)($input['expires_at'] ?? '')) ?: null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $profileId = (int)$db->lastInsertId();
    hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'create', null, [
        'consent_type' => $profileInput['consent_type'],
        'usage_scope' => $profileInput['usage_scope'],
        'visibility' => $profileInput['visibility'],
    ]);

    return $profileId;
}

function hub_get_voice_profile(PDO $db, int $profileId): ?array
{
    $stmt = $db->prepare('SELECT * FROM voice_profiles WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $profileId]);
    $profile = $stmt->fetch();

    return $profile ?: null;
}

function hub_get_voice_profile_for_member(PDO $db, int $profileId, int $memberId): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM voice_profiles
         WHERE id = :id
           AND deleted_at IS NULL
           AND (owner_member_id = :owner_member_id OR visibility = "shared")'
    );
    $stmt->execute([':id' => $profileId, ':owner_member_id' => $memberId]);
    $profile = $stmt->fetch();

    return $profile ?: null;
}

function hub_confirm_voice_profile_prompt(PDO $db, int $profileId, int $ownerMemberId, string $promptText): array
{
    $confirmationTransactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $confirmationTransactionStarted = true;
        $profile = hub_confirm_voice_profile_prompt_in_transaction($db, $profileId, $ownerMemberId, $promptText);
        $db->exec('COMMIT');
        $confirmationTransactionStarted = false;
        return $profile;
    } catch (Throwable $e) {
        if ($confirmationTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }
}

function hub_voice_profile_confirmation_text_is_valid(string $promptText): bool
{
    if ($promptText === '' || strlen($promptText) > 80000 || preg_match('//u', $promptText) !== 1) {
        return false;
    }
    if (preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $promptText) === 1) {
        return false;
    }
    $characters = function_exists('mb_strlen')
        ? mb_strlen($promptText, 'UTF-8')
        : preg_match_all('/./us', $promptText);

    return is_int($characters) && $characters <= 20000;
}

function hub_confirm_voice_profile_prompt_in_transaction(PDO $db, int $profileId, int $ownerMemberId, string $promptText): array
{
    if (!hub_voice_profile_confirmation_text_is_valid($promptText)) {
        throw new InvalidArgumentException('voice_profile_transcript_invalid');
    }

    $stmt = $db->prepare('SELECT * FROM voice_profiles WHERE id = :id AND owner_member_id = :owner_member_id');
    $stmt->execute([':id' => $profileId, ':owner_member_id' => $ownerMemberId]);
    $profile = $stmt->fetch();
    if (!$profile) {
        throw new InvalidArgumentException('voice_profile_transcript_invalid');
    }
    if (!empty($profile['deleted_at'])) {
        throw new InvalidArgumentException('voice_profile_unavailable');
    }
    $now = hub_now();
    $stmt = $db->prepare('UPDATE voice_profiles SET prompt_text = :prompt_text, prompt_text_confirmed_at = :confirmed_at, transcription_status = :transcription_status, transcription_error = NULL, transcription_started_at = NULL, transcription_lease_token = NULL, updated_at = :updated_at WHERE id = :id AND owner_member_id = :owner_member_id AND deleted_at IS NULL');
    $stmt->execute([
        ':prompt_text' => $promptText,
        ':confirmed_at' => $now,
        ':transcription_status' => 'ready',
        ':updated_at' => $now,
        ':id' => $profileId,
        ':owner_member_id' => $ownerMemberId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new InvalidArgumentException('voice_profile_transcript_invalid');
    }
    hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'confirm_transcript', null, ['text_chars' => function_exists('mb_strlen') ? mb_strlen($promptText, 'UTF-8') : strlen($promptText)]);

    return hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing');
}

function hub_purge_deleted_voice_profile_audio(PDO $db, int $profileId, string $rawPath, string $expectedSha256, ?callable $beforeDispose = null): bool
{
    if ($rawPath === '') {
        return true;
    }
    $transactionStarted = false;
    $quarantineDir = null;
    $quarantinePath = null;
    $quarantine = null;
    $path = null;
    try {
        if (!$db->inTransaction()) {
            $db->exec('BEGIN IMMEDIATE');
            $transactionStarted = true;
        }
        $path = hub_voice_profile_safe_host_path($rawPath);
        if ($path === null) {
            if (@lstat($rawPath) !== false) {
                throw new RuntimeException('voice_profile_audio_path_rejected');
            }
        } else {
            $active = $db->prepare(
                'SELECT 1 FROM voice_profiles
                 WHERE id <> :id AND deleted_at IS NULL AND reference_audio_path = :reference_audio_path
                 LIMIT 1'
            );
            $active->execute([':id' => $profileId, ':reference_audio_path' => $rawPath]);
            if ($active->fetchColumn() !== false) {
                throw new RuntimeException('voice_profile_audio_still_referenced');
            }
            $expectedShaIsValid = preg_match('/^[a-f0-9]{64}$/', $expectedSha256) === 1;
            if (!$expectedShaIsValid) {
                $legacy = $db->prepare(
                    "SELECT 1 FROM voice_profiles
                     WHERE id = :id AND deleted_at IS NOT NULL
                       AND reference_audio_path = :reference_audio_path
                       AND reference_audio_sha256 = ''
                     LIMIT 1"
                );
                $legacy->execute([':id' => $profileId, ':reference_audio_path' => $rawPath]);
                if ($legacy->fetchColumn() === false) {
                    throw new RuntimeException('voice_profile_audio_identity_missing');
                }
            }

            $before = @lstat($path);
            $quarantineDir = dirname($path) . '/.voice_profile_purge_' . bin2hex(random_bytes(16));
            $quarantinePath = $quarantineDir . '/audio.wav';
            if (
                !is_array($before)
                || !@mkdir($quarantineDir, 0700)
                || !@rename($path, $quarantinePath)
            ) {
                throw new RuntimeException('voice_profile_audio_quarantine_failed');
            }
            clearstatcache(true, $quarantinePath);
            $quarantine = @fopen($quarantinePath, 'r+b');
            $opened = is_resource($quarantine) ? fstat($quarantine) : false;
            if (
                !is_resource($quarantine)
                || !hub_voice_profile_file_stats_match($before, $opened)
                || !hub_voice_profile_file_stats_match($opened, @lstat($quarantinePath))
            ) {
                throw new RuntimeException('voice_profile_audio_identity_changed');
            }
            $hash = hash_init('sha256');
            hash_update_stream($hash, $quarantine);
            $digest = hash_final($hash);
            clearstatcache(true, $quarantinePath);
            if (
                !hub_voice_profile_file_stats_match(fstat($quarantine), @lstat($quarantinePath))
                || ($expectedShaIsValid && !hash_equals($expectedSha256, $digest))
            ) {
                throw new RuntimeException('voice_profile_audio_identity_changed');
            }
            if (!hub_voice_profile_scrub_and_unlink($quarantine, $quarantinePath, $beforeDispose)) {
                throw new RuntimeException('voice_profile_audio_cleanup_failed');
            }
            fclose($quarantine);
            $quarantine = null;
            @rmdir($quarantineDir);
            $quarantineDir = null;
            $quarantinePath = null;
        }

        $stmt = $db->prepare(
            "UPDATE voice_profiles
             SET reference_audio_path = '', updated_at = :updated_at
             WHERE id = :id AND deleted_at IS NOT NULL"
        );
        $stmt->execute([':updated_at' => hub_now(), ':id' => $profileId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('voice_profile_cleanup_conflict');
        }
        if ($transactionStarted) {
            $db->exec('COMMIT');
            $transactionStarted = false;
        }

        return true;
    } catch (Throwable) {
        if (is_resource($quarantine)) {
            fclose($quarantine);
        }
        if (
            is_string($quarantinePath)
            && is_string($path)
            && @lstat($quarantinePath) !== false
            && @lstat($path) === false
        ) {
            @rename($quarantinePath, $path);
        }
        if (is_string($quarantineDir)) {
            @rmdir($quarantineDir);
        }
        if ($transactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }

        return false;
    }
}

function hub_soft_delete_voice_profile(PDO $db, int $profileId, int $ownerMemberId, bool $deleteAudio = false): array
{
    $rawPath = '';
    $referenceAudioSha256 = '';
    $deleteTransactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $deleteTransactionStarted = true;
        $stmt = $db->prepare('SELECT * FROM voice_profiles WHERE id = :id AND owner_member_id = :owner_member_id');
        $stmt->execute([':id' => $profileId, ':owner_member_id' => $ownerMemberId]);
        $profile = $stmt->fetch();
        if (!$profile) {
            throw new InvalidArgumentException('voice_profile_forbidden');
        }
        $rawPath = (string)($profile['reference_audio_path'] ?? '');
        $referenceAudioSha256 = (string)($profile['reference_audio_sha256'] ?? '');
        $alreadyDeleted = !empty($profile['deleted_at']);
        $now = hub_now();
        $stmt = $db->prepare(
            "UPDATE voice_profiles
                 SET deleted_at = COALESCE(deleted_at, :deleted_at),
                 name = 'Deleted voice profile',
                 reference_audio_sha256 = '',
                 prompt_text = NULL,
                 transcript_validation_json = NULL,
                 prompt_text_confirmed_at = NULL,
                 language = NULL,
                 transcription_status = 'failed',
                 transcription_error = NULL,
                 transcription_started_at = NULL,
                 transcription_lease_token = NULL,
                 retain_original_audio = 0,
                 expires_at = CASE
                     WHEN expires_at IS NULL OR expires_at > :expires_at THEN :expires_at
                     ELSE expires_at
                 END,
                 updated_at = :updated_at
             WHERE id = :id AND owner_member_id = :owner_member_id"
        );
        $stmt->execute([
            ':deleted_at' => $now,
            ':expires_at' => $now,
            ':updated_at' => $now,
            ':id' => $profileId,
            ':owner_member_id' => $ownerMemberId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new InvalidArgumentException('voice_profile_forbidden');
        }
        if (!$alreadyDeleted) {
            hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'delete', null, [
                'delete_audio' => $deleteAudio,
                'reference_audio_sha256' => $referenceAudioSha256,
            ]);
        }
        $db->exec('COMMIT');
        $deleteTransactionStarted = false;
    } catch (Throwable $e) {
        if ($deleteTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }

    return [
        'audio_cleanup_failed' => $deleteAudio
            && !hub_purge_deleted_voice_profile_audio($db, $profileId, $rawPath, $referenceAudioSha256),
    ];
}

function hub_prune_expired_voice_profiles(PDO $db, string $now, int $limit = 100): array
{
    hub_cleanup_stale_voice_profile_snapshots(strtotime($now) ?: time());

    $stmt = $db->prepare(
        "SELECT id, owner_member_id, reference_audio_path, reference_audio_sha256, deleted_at
         FROM voice_profiles
         WHERE expires_at IS NOT NULL AND expires_at <= :now
           AND (
               deleted_at IS NULL
               OR reference_audio_path <> ''
               OR reference_audio_sha256 <> ''
               OR prompt_text IS NOT NULL
               OR transcript_validation_json IS NOT NULL
               OR prompt_text_confirmed_at IS NOT NULL
               OR language IS NOT NULL
               OR name <> 'Expired voice profile'
           )
         ORDER BY updated_at ASC, expires_at ASC, id ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':now', $now, PDO::PARAM_STR);
    $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    $profilesDeleted = 0;
    $audioPurged = 0;
    $errors = 0;

    foreach ($stmt->fetchAll() as $profile) {
        $profileId = (int)$profile['id'];
        $rawPath = (string)$profile['reference_audio_path'];
        $referenceAudioSha256 = (string)$profile['reference_audio_sha256'];
        if ($rawPath !== '' && preg_match('/^[a-f0-9]{64}$/', $referenceAudioSha256) !== 1) {
            $audit = $db->prepare(
                "SELECT details_json FROM voice_profile_audit_logs
                 WHERE voice_profile_id = :voice_profile_id AND action = 'delete'
                 ORDER BY id DESC LIMIT 1"
            );
            $audit->execute([':voice_profile_id' => $profileId]);
            $details = json_decode((string)$audit->fetchColumn(), true);
            $referenceAudioSha256 = is_array($details)
                ? (string)($details['reference_audio_sha256'] ?? '')
                : '';
        }
        $safePath = $rawPath === '' ? null : hub_voice_profile_safe_host_path($rawPath);
        $managedAudio = $safePath !== null && is_file($safePath) && !is_link($safePath) ? $safePath : null;
        $unsafeAudio = $rawPath !== '' && $managedAudio === null && (file_exists($rawPath) || is_link($rawPath));
        try {
            if (empty($profile['deleted_at'])) {
                hub_soft_delete_voice_profile($db, $profileId, (int)$profile['owner_member_id'], false);
                $profilesDeleted++;
            }
            $scrub = $db->prepare(
                "UPDATE voice_profiles
                 SET name = 'Expired voice profile',
                     reference_audio_sha256 = '',
                     prompt_text = NULL,
                     transcript_validation_json = NULL,
                     prompt_text_confirmed_at = NULL,
                     language = NULL,
                     transcription_error = NULL,
                     transcription_started_at = NULL,
                     transcription_lease_token = NULL,
                     retain_original_audio = 0,
                     updated_at = :updated_at
                 WHERE id = :id AND deleted_at IS NOT NULL"
            );
            $scrub->execute([':updated_at' => $now, ':id' => $profileId]);
            if ($scrub->rowCount() !== 1) {
                throw new RuntimeException('voice_profile_cleanup_conflict');
            }
            if ($unsafeAudio) {
                throw new RuntimeException('voice_profile_audio_path_rejected');
            }
            if ($rawPath !== '') {
                if (!hub_purge_deleted_voice_profile_audio($db, $profileId, $rawPath, $referenceAudioSha256)) {
                    throw new RuntimeException('voice_profile_audio_cleanup_failed');
                }
                if ($managedAudio !== null) {
                    $audioPurged++;
                }
            }
        } catch (Throwable) {
            $touch = $db->prepare(
                'UPDATE voice_profiles SET updated_at = :updated_at
                 WHERE id = :id AND expires_at IS NOT NULL AND expires_at <= :expires_at'
            );
            $touch->execute([':updated_at' => $now, ':id' => $profileId, ':expires_at' => $now]);
            $errors++;
        }
    }

    return [
        'profiles_deleted' => $profilesDeleted,
        'audio_purged' => $audioPurged,
        'errors' => $errors,
    ];
}

function hub_record_voice_profile_audit(PDO $db, ?int $profileId, ?int $ownerMemberId, ?int $tokenId, string $action, ?string $mode, array $details = []): void
{
    $stmt = $db->prepare(
        'INSERT INTO voice_profile_audit_logs
            (voice_profile_id, owner_member_id, token_id, action, mode, details_json, created_at)
         VALUES
            (:voice_profile_id, :owner_member_id, :token_id, :action, :mode, :details_json, :created_at)'
    );
    $stmt->execute([
        ':voice_profile_id' => $profileId,
        ':owner_member_id' => $ownerMemberId,
        ':token_id' => $tokenId,
        ':action' => $action,
        ':mode' => $mode,
        ':details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':created_at' => hub_now(),
    ]);
}
