<?php
declare(strict_types=1);

function hub_list_packs(): array
{
    $packs = [];
    $seen = [];
    foreach (hub_load_pack_catalog()['packs'] as $entry) {
        $pack = hub_read_pack_from_catalog_entry($entry);
        $packs[] = $pack;
        $seen[$pack['id']] = true;
    }

    foreach (glob(HUB_ROOT . '/packs/*/pack.json') ?: [] as $manifestPath) {
        $fallbackId = basename(dirname($manifestPath));
        if (isset($seen[$fallbackId])) {
            continue;
        }
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $manifest = is_array($manifest) ? hub_normalize_pack_manifest($manifest) : $manifest;
        $errors = is_array($manifest) ? hub_validate_pack_manifest($manifest, dirname($manifestPath)) : ['pack.json is not valid JSON.'];
        $packs[] = hub_pack_record($fallbackId, dirname($manifestPath), $manifestPath, is_array($manifest) ? $manifest : [], $errors);
    }

    usort($packs, static fn (array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));
    return $packs;
}

function hub_load_pack_catalog(): array
{
    $path = HUB_ROOT . '/packs/catalog.json';
    if (!is_file($path)) {
        return ['schema_version' => '0.1', 'packs' => []];
    }

    $catalog = json_decode((string)file_get_contents($path), true);
    if (!is_array($catalog)) {
        return ['schema_version' => '', 'packs' => []];
    }
    $catalog['packs'] = is_array($catalog['packs'] ?? null) ? $catalog['packs'] : [];

    return $catalog;
}

function hub_list_catalog_packs(): array
{
    $packs = [];
    foreach (hub_load_pack_catalog()['packs'] as $entry) {
        $packs[] = hub_read_pack_from_catalog_entry($entry);
    }

    return $packs;
}

function hub_read_pack_from_catalog_entry(array $entry): array
{
    $packDir = HUB_ROOT . '/' . trim((string)($entry['path'] ?? ''), '/');
    $manifestPath = $packDir . '/pack.json';
    $manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
    if (is_array($manifest)) {
        $manifest['category'] = (string)($manifest['category'] ?? $entry['category'] ?? '');
        $manifest['description'] = (string)($manifest['description'] ?? $entry['description'] ?? '');
        $manifest = hub_normalize_pack_manifest($manifest);
    }
    $errors = is_array($manifest) ? hub_validate_pack_manifest($manifest, $packDir) : ['pack.json not found or invalid JSON.'];

    return hub_pack_record((string)($entry['id'] ?? ''), $packDir, $manifestPath, is_array($manifest) ? $manifest : [], $errors, $entry);
}

function hub_pack_record(string $fallbackId, string $packDir, string $manifestPath, array $manifest, array $errors, array $catalog = []): array
{
    $manifest = $manifest === [] ? [] : hub_normalize_pack_manifest($manifest);

    return [
        'id' => (string)($manifest['id'] ?? $fallbackId),
        'category' => (string)($manifest['category'] ?? $catalog['category'] ?? ''),
        'description' => (string)($manifest['description'] ?? $catalog['description'] ?? ''),
        'catalog' => $catalog,
        'dir' => $packDir,
        'manifest_path' => $manifestPath,
        'manifest' => $manifest,
        'status' => $errors ? 'error' : 'ok',
        'errors' => $errors,
    ];
}

function hub_get_pack(string $packId): ?array
{
    foreach (hub_list_packs() as $pack) {
        if (($pack['id'] ?? '') === $packId) {
            return $pack;
        }
    }

    return null;
}

function hub_audio_async_routes(): array
{
    return [
        'audio_cleanup' => ['pack_id' => 'audio-cleanup', 'job' => 'cleanup'],
        'speech_transcribe' => ['pack_id' => 'whisper-asr', 'job' => 'transcribe'],
        'voice_generate' => ['pack_id' => 'tts-voxcpm2', 'job' => 'synthesize'],
    ];
}

function hub_is_audio_async_mode(string $mode): bool
{
    return array_key_exists($mode, hub_audio_async_routes());
}

function hub_resolve_audio_async_route(PDO $db, string $requestedMode): array
{
    $route = hub_audio_async_routes()[$requestedMode] ?? null;
    if ($route === null) {
        throw new InvalidArgumentException('unknown_audio_async_mode');
    }

    $pack = hub_get_pack((string)$route['pack_id']);
    if (!$pack || ($pack['status'] ?? '') !== 'ok') {
        throw new RuntimeException('pack_not_installed');
    }
    $packVersion = (string)($pack['manifest']['version'] ?? '');
    if ($packVersion === '') {
        throw new RuntimeException('pack_version_unavailable');
    }
    $stmt = $db->prepare(
        "SELECT pack_version FROM services
         WHERE pack_id = :pack_id AND install_status = 'installed'
         ORDER BY id DESC"
    );
    $stmt->execute([':pack_id' => $route['pack_id']]);
    $installedVersions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($installedVersions === []) {
        throw new RuntimeException('pack_not_installed');
    }
    if (!in_array($packVersion, array_map('strval', $installedVersions), true)) {
        throw new RuntimeException('pack_version_unavailable');
    }
    $jobContract = hub_pack_async_job_contract((array)($pack['manifest'] ?? []), (string)$route['job']);
    if ($jobContract === null) {
        throw new RuntimeException('pack_version_unavailable');
    }

    $snapshot = hub_pack_job_contract_snapshot($jobContract);
    return [
        'requested_mode' => $requestedMode,
        'pack_id' => $route['pack_id'],
        'pack_version' => $packVersion,
        'job' => $route['job'],
        'runtime_mode' => 'job',
        'accelerator' => 'gpu',
        'route_resolved_at' => hub_now(),
        'job_contract_json' => $snapshot['json'],
        'job_contract_digest' => $snapshot['digest'],
    ] + $jobContract;
}

function hub_revalidate_audio_async_route(PDO $db, array $snapshot): array
{
    $requestedMode = (string)($snapshot['requested_mode'] ?? '');
    if (!hub_is_audio_async_mode($requestedMode)) {
        throw new RuntimeException('pack_version_unavailable');
    }
    hub_resolve_stored_pack_job($db, $snapshot);
    $route = hub_resolve_audio_async_route($db, $requestedMode);
    foreach (['pack_id', 'pack_version', 'job', 'runtime_mode', 'accelerator'] as $field) {
        if (($snapshot[$field] ?? null) !== ($route[$field] ?? null)) {
            throw new RuntimeException('pack_version_unavailable');
        }
    }

    return $route;
}

function hub_pack_async_job_contract(array $manifest, string $job): ?array
{
    $jobs = $manifest['async_jobs'] ?? null;
    if (!is_array($jobs) || !array_is_list($jobs)) {
        return null;
    }
    foreach ($jobs as $definition) {
        if (!is_array($definition) || (string)($definition['job'] ?? '') !== $job) {
            continue;
        }
        $input = $definition['input'] ?? null;
        if (!is_array($input)) {
            return null;
        }
        $fields = hub_pack_async_job_contract_names($input['fields'] ?? null, '/^[a-z][a-z0-9_]*$/');
        $artifactTypes = hub_pack_async_job_contract_names($input['source_artifact_types'] ?? null, '/^[a-z][a-z0-9_-]*$/');
        $requestSchema = hub_pack_async_job_request_schema($input['request_schema'] ?? [], $fields ?? []);
        $voiceContext = hub_pack_async_job_voice_context_contract($input['voice_context'] ?? null, $fields ?? [], $requestSchema ?? []);
        $maxUploadBytes = hub_pack_async_job_max_upload_bytes($definition, $manifest);
        $output = $definition['output'] ?? null;
        if ($fields === null || $artifactTypes === null || $requestSchema === null || $voiceContext === null || $maxUploadBytes === null || !is_array($output)
            || array_diff(array_keys($output), ['artifacts', 'report_attestation']) !== []) {
            return null;
        }
        try {
            $artifacts = hub_pack_job_contract_artifacts($output);
            $attestation = hub_pack_job_report_attestation_contract($output['report_attestation'] ?? null, $artifacts);
        } catch (HubPackOutputContractInvalid) {
            return null;
        }
        $artifactContract = ['artifacts' => $artifacts] + ($attestation === null ? [] : ['report_attestation' => $attestation]);
        $runner = null;
        if (array_key_exists('runner', $definition)) {
            $runner = hub_pack_async_job_runner_contract($definition['runner'], $fields, $requestSchema);
            if ($runner === null) {
                return null;
            }
        }
        $runnerConfig = null;
        if (array_key_exists('runner_config', $definition)) {
            $runnerConfig = hub_pack_async_job_runner_config_from_manifest($definition['runner_config'], $manifest, $fields, $requestSchema);
            if ($runnerConfig === null || $runner === null) {
                return null;
            }
        }
        $capabilities = hub_pack_async_job_capabilities($definition['capabilities'] ?? []);
        $capabilityRequirements = hub_pack_async_job_capability_requirements($definition['capability_requirements'] ?? [], $capabilities ?? []);
        if ($capabilities === null || $capabilityRequirements === null) {
            return null;
        }

        return [
            'input_fields' => $fields,
            'source_artifact_types' => $artifactTypes,
            'request_schema' => $requestSchema,
            'max_upload_bytes' => $maxUploadBytes,
            'artifact_contract' => $artifactContract,
        ] + ($voiceContext === [] ? [] : ['voice_context' => $voiceContext]) + ($runner === null ? [] : ['runner' => $runner])
            + ($runnerConfig === null ? [] : ['runner_config' => $runnerConfig])
            + ($capabilities === [] ? [] : ['capabilities' => $capabilities])
            + ($capabilityRequirements === [] ? [] : ['capability_requirements' => $capabilityRequirements]);
    }

    return null;
}

function hub_pack_async_job_runner_asset_marker_json(mixed $marker, array $requiredPaths): ?array
{
    if (!is_array($marker) || array_diff(array_keys($marker), ['path', 'required_strings', 'string_lists', 'input_membership', 'exact_keys']) !== []
        || !array_key_exists('path', $marker) || !array_key_exists('required_strings', $marker) || !array_key_exists('exact_keys', $marker)) {
        return null;
    }
    $path = $marker['path'];
    $requiredStrings = $marker['required_strings'];
    $exactKeys = $marker['exact_keys'];
    if (!is_string($path) || !in_array($path, $requiredPaths, true)
        || !is_array($requiredStrings) || array_is_list($requiredStrings) || $requiredStrings === [] || count($requiredStrings) > 16
        || !is_array($exactKeys) || !array_is_list($exactKeys) || $exactKeys === [] || count($exactKeys) > 24) {
        return null;
    }
    $normalizedStrings = [];
    foreach ($requiredStrings as $field => $value) {
        if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) !== 1
            || !is_string($value) || $value === '' || strlen($value) > 512 || str_contains($value, "\0")) {
            return null;
        }
        $normalizedStrings[$field] = $value;
    }
    $stringLists = $marker['string_lists'] ?? [];
    if (!is_array($stringLists) || ($stringLists !== [] && array_is_list($stringLists)) || count($stringLists) > 8) {
        return null;
    }
    $normalizedLists = [];
    foreach ($stringLists as $field => $values) {
        if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) !== 1 || isset($normalizedStrings[$field])
            || !is_array($values) || !array_is_list($values) || $values === [] || count($values) > 32) {
            return null;
        }
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^(?:\\*|[A-Za-z0-9][A-Za-z0-9._-]{0,63})$/', $value) !== 1 || isset($seen[$value])) {
                return null;
            }
            $seen[$value] = true;
        }
        $normalizedLists[$field] = array_values($values);
    }
    $seenKeys = [];
    foreach ($exactKeys as $field) {
        if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) !== 1 || isset($seenKeys[$field])) {
            return null;
        }
        $seenKeys[$field] = true;
    }
    $declaredFields = array_keys($normalizedStrings + $normalizedLists);
    if (count($exactKeys) !== count($declaredFields) || array_diff($exactKeys, $declaredFields) !== [] || array_diff($declaredFields, $exactKeys) !== []) {
        return null;
    }
    $membership = $marker['input_membership'] ?? null;
    if ($membership !== null && (!is_array($membership) || array_keys($membership) !== ['input', 'list_field']
        || !is_string($membership['input'] ?? null) || preg_match('/^[a-z][a-z0-9_]*$/', $membership['input']) !== 1
        || !is_string($membership['list_field'] ?? null) || !isset($normalizedLists[$membership['list_field']]))) {
        return null;
    }

    return [
        'path' => $path,
        'required_strings' => $normalizedStrings,
        'exact_keys' => array_values($exactKeys),
    ] + ($normalizedLists === [] ? [] : ['string_lists' => $normalizedLists])
        + ($membership === null ? [] : ['input_membership' => [
            'input' => $membership['input'],
            'list_field' => $membership['list_field'],
        ]]);
}

function hub_pack_async_job_runner_asset_mounts(mixed $mounts): ?array
{
    if (!is_array($mounts) || !array_is_list($mounts) || count($mounts) > 8) {
        return null;
    }
    $normalized = [];
    $seen = [];
    foreach ($mounts as $mount) {
        if (!is_array($mount) || array_diff(array_keys($mount), ['id', 'storage', 'host_subdir', 'container_path', 'required_paths', 'when', 'marker_json']) !== []) {
            return null;
        }
        $id = (string)($mount['id'] ?? '');
        $storage = (string)($mount['storage'] ?? '');
        $hostSubdir = (string)($mount['host_subdir'] ?? '');
        $containerPath = (string)($mount['container_path'] ?? '');
        $requiredPaths = $mount['required_paths'] ?? null;
        $when = $mount['when'] ?? null;
        $hasMarkerJson = array_key_exists('marker_json', $mount);
        $markerJson = $mount['marker_json'] ?? null;
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id) !== 1
            || !in_array($storage, ['models', 'cache'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*)*$/', $hostSubdir) !== 1
            || strlen($hostSubdir) > 240
            || preg_match($storage === 'models' ? '~^/models/[A-Za-z0-9][A-Za-z0-9._/-]{0,239}$~' : '~^/cache/[A-Za-z0-9][A-Za-z0-9._/-]{0,239}$~', $containerPath) !== 1
            || !is_array($requiredPaths) || !array_is_list($requiredPaths) || $requiredPaths === [] || count($requiredPaths) > 64) {
            return null;
        }
        if ($when !== null && (!is_array($when) || array_keys($when) !== ['input', 'equals']
            || !is_string($when['input'] ?? null) || preg_match('/^[a-z][a-z0-9_]*$/', $when['input']) !== 1
            || !is_scalar($when['equals'] ?? null))) {
            return null;
        }
        foreach ($requiredPaths as $path) {
            if (!is_string($path) || strlen($path) > 240
                || preg_match('/^(?!\/)(?!.*(?:^|\/)\.\.?\/?(?:$|\/))[A-Za-z0-9.][A-Za-z0-9._-]*(?:\/[A-Za-z0-9.][A-Za-z0-9._-]*)*$/', $path) !== 1) {
                return null;
            }
        }
        $markerContract = $hasMarkerJson ? hub_pack_async_job_runner_asset_marker_json($markerJson, $requiredPaths) : null;
        if ($hasMarkerJson && $markerContract === null) {
            return null;
        }
        if (isset($seen[$id]) || isset($seen[$storage . ':' . $hostSubdir]) || isset($seen['container:' . $containerPath])) {
            return null;
        }
        $seen[$id] = true;
        $seen[$storage . ':' . $hostSubdir] = true;
        $seen['container:' . $containerPath] = true;
        $normalized[] = [
            'id' => $id,
            'storage' => $storage,
            'host_subdir' => $hostSubdir,
            'container_path' => $containerPath,
            'required_paths' => array_values($requiredPaths),
        ] + ($when === null ? [] : ['when' => ['input' => $when['input'], 'equals' => $when['equals']]])
            + ($markerContract === null ? [] : ['marker_json' => $markerContract]);
    }

    return $normalized;
}

function hub_pack_async_job_runner_asset_mount_conditions_valid(array $mounts, array $fields, array $requestSchema): bool
{
    $allowed = array_fill_keys($fields, true);
    foreach ($mounts as $mount) {
        if (isset($mount['when'])) {
            $when = $mount['when'];
            $input = $when['input'] ?? null;
            $equals = $when['equals'] ?? null;
            $definition = is_string($input) && isset($allowed[$input]) ? ($requestSchema[$input] ?? null) : null;
            if (!is_array($definition)) {
                return false;
            }
            if (($definition['type'] ?? '') === 'boolean') {
                if (!is_bool($equals)) {
                    return false;
                }
            } elseif (($definition['type'] ?? '') === 'integer') {
                if (!is_int($equals) || $equals < ($definition['min'] ?? 0) || $equals > ($definition['max'] ?? 0)) {
                    return false;
                }
            } elseif (($definition['type'] ?? '') === 'string') {
                if (!is_string($equals) || $equals === '' || strlen($equals) > ($definition['max_length'] ?? 0)
                    || (isset($definition['enum']) && !in_array($equals, $definition['enum'], true))) {
                    return false;
                }
            } else {
                return false;
            }
        }
        $membership = $mount['marker_json']['input_membership'] ?? null;
        if ($membership === null) {
            continue;
        }
        $input = $membership['input'] ?? null;
        $definition = is_string($input) && isset($allowed[$input]) ? ($requestSchema[$input] ?? null) : null;
        if (!is_array($definition) || ($definition['type'] ?? '') !== 'string'
        ) {
            return false;
        }
    }

    return true;
}

function hub_pack_async_job_runner_contract(mixed $runner, ?array $fields = null, ?array $requestSchema = null): ?array
{
    if (!is_array($runner) || array_diff(array_keys($runner), ['image', 'entrypoint', 'args', 'output_dir', 'accelerator', 'required_vram_mb', 'timeout_seconds', 'executor', 'secret_env', 'asset_mounts']) !== []) {
        return null;
    }
    $image = trim((string)($runner['image'] ?? ''));
    $entrypoint = $runner['entrypoint'] ?? null;
    $args = $runner['args'] ?? null;
    $outputDir = (string)($runner['output_dir'] ?? '');
    $accelerator = (string)($runner['accelerator'] ?? '');
    $requiredVram = $runner['required_vram_mb'] ?? null;
    $timeout = $runner['timeout_seconds'] ?? null;
    $executor = $runner['executor'] ?? null;
    if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._/@:-]{0,254}$~', $image) !== 1
        || !is_array($entrypoint) || !array_is_list($entrypoint) || $entrypoint === []
        || !is_array($args) || !array_is_list($args)
        || $outputDir !== 'output' || !in_array($accelerator, ['cpu', 'gpu'], true)
        || !is_int($requiredVram) || $requiredVram < 0 || $requiredVram > 1048576
        || !is_int($timeout) || $timeout < 1 || $timeout > 86400) {
        return null;
    }
    if ($executor !== null && $executor !== 'container') {
        return null;
    }
    $secretEnv = $runner['secret_env'] ?? [];
    if (!is_array($secretEnv) || !array_is_list($secretEnv) || count($secretEnv) > 16) {
        return null;
    }
    foreach ($secretEnv as $name) {
        if (!is_string($name) || preg_match('/^AIHUB_SECRET_[A-Z0-9_]{1,63}$/', $name) !== 1) {
            return null;
        }
    }
    if (count(array_unique($secretEnv)) !== count($secretEnv)) {
        return null;
    }
    $assetMounts = hub_pack_async_job_runner_asset_mounts($runner['asset_mounts'] ?? []);
    if ($assetMounts === null || ($fields !== null && $requestSchema !== null && !hub_pack_async_job_runner_asset_mount_conditions_valid($assetMounts, $fields, $requestSchema))) {
        return null;
    }
    foreach (array_merge($entrypoint, $args) as $value) {
        if (!is_string($value) || $value === '' || strlen($value) > 1024 || str_contains($value, "\0")) {
            return null;
        }
        preg_match_all('/\{[^}]*\}/', $value, $matches);
        foreach ($matches[0] as $template) {
            if (!in_array($template, ['{workspace}', '{input_dir}', '{output_dir}', '{run_id}', '{task_id}'], true)) {
                return null;
            }
        }
    }

    return [
        'image' => $image,
        'entrypoint' => array_values($entrypoint),
        'args' => array_values($args),
        'output_dir' => 'output',
        'accelerator' => $accelerator,
        'required_vram_mb' => $requiredVram,
        'timeout_seconds' => $timeout,
    ] + ($executor === null ? [] : ['executor' => $executor])
        + ($secretEnv === [] ? [] : ['secret_env' => $secretEnv])
        + ($assetMounts === [] ? [] : ['asset_mounts' => $assetMounts]);
}

function hub_pack_async_job_runner_config_value(mixed $value, int $depth = 0): bool
{
    if (is_string($value)) {
        return $value !== '' && strlen($value) <= 1024 && !str_contains($value, "\0");
    }
    if (is_int($value) || is_float($value) || is_bool($value)) {
        return true;
    }
    if (!is_array($value) || $depth >= 4 || count($value) > 64) {
        return false;
    }
    foreach ($value as $key => $item) {
        if ((!is_int($key) && (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $key) !== 1))
            || !hub_pack_async_job_runner_config_value($item, $depth + 1)) {
            return false;
        }
    }

    return true;
}

function hub_pack_async_job_runner_config(mixed $config, array $fields, array $requestSchema): ?array
{
    if (!is_array($config) || array_diff(array_keys($config), ['alias_input', 'model_allowlist', 'aliases']) !== []) {
        return null;
    }
    $aliasInput = (string)($config['alias_input'] ?? '');
    $allowlist = (string)($config['model_allowlist'] ?? '');
    $aliases = $config['aliases'] ?? null;
    if (!in_array($aliasInput, $fields, true) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $allowlist) !== 1
        || !is_array($aliases) || $aliases === [] || array_is_list($aliases)
        || !isset($requestSchema[$aliasInput]['enum']) || array_keys($aliases) !== $requestSchema[$aliasInput]['enum']) {
        return null;
    }
    foreach ($aliases as $alias => $model) {
        if (!is_string($alias) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $alias) !== 1 || !hub_pack_async_job_runner_config_value($model)) {
            return null;
        }
    }

    return ['alias_input' => $aliasInput, 'model_allowlist' => $allowlist, 'aliases' => $aliases];
}

function hub_pack_async_job_runner_config_from_manifest(mixed $definition, array $manifest, array $fields, array $requestSchema): ?array
{
    if (!is_array($definition) || array_diff(array_keys($definition), ['alias_input', 'model_allowlist']) !== []) {
        return null;
    }
    $allowlist = (string)($definition['model_allowlist'] ?? '');
    $aliases = $manifest['model_allowlist'][$allowlist]['aliases'] ?? null;

    return hub_pack_async_job_runner_config([
        'alias_input' => $definition['alias_input'] ?? null,
        'model_allowlist' => $allowlist,
        'aliases' => $aliases,
    ], $fields, $requestSchema);
}

function hub_pack_async_job_request_schema_scalar_valid(mixed $value, array $definition): bool
{
    if (($definition['type'] ?? '') === 'string') {
        return is_string($value) && $value !== '' && strlen($value) <= ($definition['max_length'] ?? 0)
            && (!isset($definition['enum']) || in_array($value, $definition['enum'], true));
    }
    if (($definition['type'] ?? '') === 'boolean') {
        return is_bool($value);
    }

    return ($definition['type'] ?? '') === 'integer' && is_int($value)
        && $value >= ($definition['min'] ?? 0) && $value <= ($definition['max'] ?? 0);
}

function hub_pack_async_job_request_schema(mixed $schema, array $fields): ?array
{
    if (!is_array($schema) || ($schema !== [] && array_is_list($schema))) {
        return null;
    }
    $allowed = array_fill_keys($fields, true);
    $normalized = [];
    foreach ($schema as $name => $definition) {
        if (!is_string($name) || !isset($allowed[$name]) || !is_array($definition)
            || array_diff(array_keys($definition), ['type', 'required', 'enum', 'default', 'max_length', 'min', 'max', 'requires', 'gte_field', 'requires_when']) !== []) {
            return null;
        }
        $type = (string)($definition['type'] ?? 'string');
        $required = $definition['required'] ?? false;
        if (!in_array($type, ['string', 'boolean', 'integer'], true) || !is_bool($required)) {
            return null;
        }
        $item = ['type' => $type, 'required' => $required];
        if ($type === 'string') {
            $maxLength = $definition['max_length'] ?? 1024;
            if (!is_int($maxLength) || $maxLength < 1 || $maxLength > 4096) {
                return null;
            }
            $enum = $definition['enum'] ?? null;
            if ($enum !== null) {
                if (!is_array($enum) || !array_is_list($enum) || $enum === []) {
                    return null;
                }
                $seen = [];
                foreach ($enum as $value) {
                    if (!is_string($value) || $value === '' || strlen($value) > $maxLength || isset($seen[$value])) {
                        return null;
                    }
                    $seen[$value] = true;
                }
                $item['enum'] = array_keys($seen);
            }
            $item['max_length'] = $maxLength;
        } elseif (isset($definition['max_length']) || isset($definition['enum'])) {
            return null;
        }
        if ($type === 'integer') {
            $min = $definition['min'] ?? -2147483648;
            $max = $definition['max'] ?? 2147483647;
            if (!is_int($min) || !is_int($max) || $min > $max) {
                return null;
            }
            $item += ['min' => $min, 'max' => $max];
        } elseif (isset($definition['min']) || isset($definition['max']) || isset($definition['gte_field'])) {
            return null;
        }
        if (isset($definition['requires'])) {
            if (!is_array($definition['requires']) || array_is_list($definition['requires']) || $definition['requires'] === []) {
                return null;
            }
            foreach ($definition['requires'] as $field => $value) {
                if (!is_string($field) || !isset($allowed[$field]) || $field === $name || !is_scalar($value)) {
                    return null;
                }
            }
            $item['requires'] = $definition['requires'];
        }
        if (isset($definition['gte_field'])) {
            if ($type !== 'integer' || !is_string($definition['gte_field']) || !isset($allowed[$definition['gte_field']]) || $definition['gte_field'] === $name) {
                return null;
            }
            $item['gte_field'] = $definition['gte_field'];
        }
        if (array_key_exists('requires_when', $definition)) {
            $rule = $definition['requires_when'];
            if (!is_array($rule) || array_keys($rule) !== ['equals', 'field', 'not_equals']
                || !is_scalar($rule['equals'] ?? null) || !is_string($rule['field'] ?? null) || !isset($allowed[$rule['field']]) || $rule['field'] === $name
                || !is_scalar($rule['not_equals'] ?? null)) {
                return null;
            }
            $item['requires_when'] = [
                'equals' => $rule['equals'],
                'field' => $rule['field'],
                'not_equals' => $rule['not_equals'],
            ];
        }
        if (array_key_exists('default', $definition)) {
            $default = $definition['default'];
            if (($type === 'string' && (!is_string($default) || $default === '' || strlen($default) > ($item['max_length'] ?? 0) || (isset($item['enum']) && !in_array($default, $item['enum'], true))))
                || ($type === 'boolean' && !is_bool($default))
                || ($type === 'integer' && (!is_int($default) || $default < $item['min'] || $default > $item['max']))) {
                return null;
            }
            $item['default'] = $default;
        }
        $normalized[$name] = $item;
    }
    foreach ($normalized as $name => $definition) {
        $rule = $definition['requires_when'] ?? null;
        if ($rule === null) {
            continue;
        }
        $target = $normalized[$rule['field']] ?? null;
        if (!is_array($target) || !hub_pack_async_job_request_schema_scalar_valid($rule['equals'], $definition)
            || !hub_pack_async_job_request_schema_scalar_valid($rule['not_equals'], $target)) {
            return null;
        }
    }

    return $normalized;
}

function hub_pack_async_job_voice_context_contract(mixed $definition, array $fields, array $requestSchema): ?array
{
    if ($definition === null) {
        return [];
    }
    if (!is_array($definition) || array_keys($definition) !== ['mode_input', 'design_value', 'clone_value', 'profile_input', 'container_path']) {
        return null;
    }
    $modeInput = $definition['mode_input'] ?? null;
    $designValue = $definition['design_value'] ?? null;
    $cloneValue = $definition['clone_value'] ?? null;
    $profileInput = $definition['profile_input'] ?? null;
    $containerPath = $definition['container_path'] ?? null;
    if (!is_string($modeInput) || !is_string($designValue) || !is_string($cloneValue) || !is_string($profileInput) || !is_string($containerPath)
        || !in_array($modeInput, $fields, true) || !in_array($profileInput, $fields, true) || $designValue === '' || $cloneValue === '' || $designValue === $cloneValue
        || ($requestSchema[$modeInput]['type'] ?? null) !== 'string' || !in_array($designValue, (array)($requestSchema[$modeInput]['enum'] ?? []), true)
        || !in_array($cloneValue, (array)($requestSchema[$modeInput]['enum'] ?? []), true) || ($requestSchema[$profileInput]['type'] ?? null) !== 'integer'
        || $containerPath !== '/data/voice_profiles/reference.wav') {
        return null;
    }

    return [
        'mode_input' => $modeInput,
        'design_value' => $designValue,
        'clone_value' => $cloneValue,
        'profile_input' => $profileInput,
        'container_path' => $containerPath,
    ];
}

function hub_pack_async_job_capabilities(mixed $capabilities): ?array
{
    if (!is_array($capabilities) || ($capabilities !== [] && array_is_list($capabilities))) {
        return null;
    }
    $normalized = [];
    foreach ($capabilities as $name => $available) {
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1 || !is_bool($available)) {
            return null;
        }
        $normalized[$name] = $available;
    }

    return $normalized;
}

function hub_pack_async_job_capability_requirements(mixed $requirements, array $capabilities): ?array
{
    if (!is_array($requirements) || ($requirements !== [] && array_is_list($requirements))) {
        return null;
    }
    $normalized = [];
    foreach ($requirements as $capability => $values) {
        if (!is_string($capability) || !array_key_exists($capability, $capabilities) || !is_array($values) || !array_is_list($values) || $values === []) {
            return null;
        }
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || isset($seen[$value])) {
                return null;
            }
            $seen[$value] = true;
        }
        $normalized[$capability] = array_keys($seen);
    }

    return $normalized;
}

function hub_pack_job_normalize_request_input(array $input, array $contract): array
{
    $fields = hub_pack_async_job_contract_names($contract['input_fields'] ?? null, '/^[a-z][a-z0-9_]*$/');
    $schema = hub_pack_async_job_request_schema($contract['request_schema'] ?? [], $fields ?? []);
    $capabilities = hub_pack_async_job_capabilities($contract['capabilities'] ?? []);
    $requirements = hub_pack_async_job_capability_requirements($contract['capability_requirements'] ?? [], $capabilities ?? []);
    if ($fields === null || $schema === null || $capabilities === null || $requirements === null) {
        throw new InvalidArgumentException('invalid_request');
    }
    $allowed = array_fill_keys($fields, true);
    foreach ($input as $name => $value) {
        if (!is_string($name) || !isset($allowed[$name]) || !is_scalar($value)) {
            throw new InvalidArgumentException('invalid_request');
        }
    }
    $provided = array_fill_keys(array_keys($input), true);
    foreach ($schema as $name => $definition) {
        if (!array_key_exists($name, $input)) {
            if (array_key_exists('default', $definition)) {
                $input[$name] = $definition['default'];
                continue;
            }
            if ($definition['required']) {
                throw new InvalidArgumentException('invalid_request');
            }
            continue;
        }
        $value = $input[$name];
        if ($definition['type'] === 'string') {
            $valid = is_string($value) && $value !== '' && strlen($value) <= $definition['max_length']
                && (!isset($definition['enum']) || in_array($value, $definition['enum'], true));
        } elseif ($definition['type'] === 'boolean') {
            $valid = is_bool($value) || (is_string($value) && in_array(strtolower($value), ['0', '1', 'false', 'true'], true));
            if ($valid) {
                $input[$name] = is_bool($value) ? $value : in_array(strtolower($value), ['1', 'true'], true);
            }
        } else {
            $valid = is_int($value) || (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) === 1);
            if ($valid) {
                $integer = (int)$value;
                $valid = $integer >= $definition['min'] && $integer <= $definition['max'];
                if ($valid) {
                    $input[$name] = $integer;
                }
            }
        }
        if (!$valid) {
            throw new InvalidArgumentException('invalid_request');
        }
    }
    foreach ($schema as $name => $definition) {
        if (!isset($provided[$name])) {
            continue;
        }
        foreach ((array)($definition['requires'] ?? []) as $field => $expected) {
            if (!array_key_exists($field, $input) || $input[$field] !== $expected) {
                throw new InvalidArgumentException('invalid_request');
            }
        }
        if (isset($definition['gte_field']) && array_key_exists($definition['gte_field'], $input)
            && $input[$name] < $input[$definition['gte_field']]) {
            throw new InvalidArgumentException('invalid_request');
        }
    }
    foreach ($schema as $name => $definition) {
        $rule = $definition['requires_when'] ?? null;
        if ($rule !== null && ($input[$name] ?? null) === $rule['equals']
            && ($input[$rule['field']] ?? null) === $rule['not_equals']) {
            throw new InvalidArgumentException('invalid_request');
        }
    }
    foreach ($requirements as $capability => $values) {
        if (!$capabilities[$capability]) {
            foreach ($input as $value) {
                if (is_string($value) && in_array($value, $values, true)) {
                    throw new InvalidArgumentException('capability_unavailable');
                }
            }
        }
    }

    return $input;
}

function hub_pack_job_contract_snapshot(array $contract): array
{
    $fields = hub_pack_async_job_contract_names($contract['input_fields'] ?? null, '/^[a-z][a-z0-9_]*$/');
    $artifactTypes = hub_pack_async_job_contract_names($contract['source_artifact_types'] ?? null, '/^[a-z][a-z0-9_-]*$/');
    $maxUploadBytes = hub_pack_async_job_positive_bytes($contract['max_upload_bytes'] ?? null, 1);
    $attestation = null;
    try {
        $artifactDefinition = (array)($contract['artifact_contract'] ?? []);
        $artifacts = hub_pack_job_contract_artifacts($artifactDefinition);
        $attestation = hub_pack_job_report_attestation_contract($artifactDefinition['report_attestation'] ?? null, $artifacts);
    } catch (HubPackOutputContractInvalid) {
        $artifacts = null;
    }
    if ($fields === null || $artifactTypes === null || $maxUploadBytes === null || $artifacts === null) {
        throw new InvalidArgumentException('job_contract_unavailable');
    }
    $snapshot = [
        'input_fields' => $fields,
        'source_artifact_types' => $artifactTypes,
        'request_schema' => hub_pack_async_job_request_schema($contract['request_schema'] ?? [], $fields) ?? throw new InvalidArgumentException('job_contract_unavailable'),
        'max_upload_bytes' => $maxUploadBytes,
        'artifact_contract' => ['artifacts' => $artifacts] + ($attestation === null ? [] : ['report_attestation' => $attestation]),
    ];
    $voiceContext = hub_pack_async_job_voice_context_contract($contract['voice_context'] ?? null, $fields, $snapshot['request_schema']);
    if ($voiceContext === null) {
        throw new InvalidArgumentException('job_contract_unavailable');
    }
    if ($voiceContext !== []) {
        $snapshot['voice_context'] = $voiceContext;
    }
    $capabilities = hub_pack_async_job_capabilities($contract['capabilities'] ?? []);
    $requirements = hub_pack_async_job_capability_requirements($contract['capability_requirements'] ?? [], $capabilities ?? []);
    if ($capabilities === null || $requirements === null) {
        throw new InvalidArgumentException('job_contract_unavailable');
    }
    if ($capabilities !== []) {
        $snapshot['capabilities'] = $capabilities;
    }
    if ($requirements !== []) {
        $snapshot['capability_requirements'] = $requirements;
    }
    if (array_key_exists('runner', $contract)) {
        $runner = hub_pack_async_job_runner_contract($contract['runner'], $fields, $snapshot['request_schema']);
        if ($runner === null) {
            throw new InvalidArgumentException('job_contract_unavailable');
        }
        $snapshot['runner'] = $runner;
    }
    if (array_key_exists('runner_config', $contract)) {
        $runnerConfig = hub_pack_async_job_runner_config($contract['runner_config'], $fields, $snapshot['request_schema']);
        if ($runnerConfig === null || !isset($snapshot['runner'])) {
            throw new InvalidArgumentException('job_contract_unavailable');
        }
        $snapshot['runner_config'] = $runnerConfig;
    }
    $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new InvalidArgumentException('job_contract_unavailable');
    }

    return ['json' => $json, 'digest' => hash('sha256', $json), 'contract' => $snapshot];
}

function hub_pack_job_contract_from_snapshot(array $task): array
{
    $json = (string)($task['job_contract_json'] ?? '');
    $digest = (string)($task['job_contract_digest'] ?? '');
    if ($json === '' || preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
        throw new RuntimeException('job_contract_unavailable');
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('job_contract_unavailable');
    }
    try {
        $snapshot = hub_pack_job_contract_snapshot($decoded);
    } catch (Throwable) {
        throw new RuntimeException('job_contract_unavailable');
    }
    if (!hash_equals($digest, $snapshot['digest']) || !hash_equals($json, $snapshot['json'])) {
        throw new RuntimeException('job_contract_unavailable');
    }

    return $snapshot['contract'];
}

function hub_resolve_stored_pack_job(PDO $db, array $task): array
{
    foreach (['pack_id', 'pack_version', 'job'] as $field) {
        if (!is_string($task[$field] ?? null) || trim((string)$task[$field]) === '') {
            throw new RuntimeException('pack_version_unavailable');
        }
    }
    if (($task['task_type'] ?? '') !== 'pack_job' || ($task['runtime_mode'] ?? '') !== 'job' || !in_array((string)($task['accelerator'] ?? ''), ['cpu', 'gpu'], true)) {
        throw new RuntimeException('job_unavailable');
    }
    $pack = hub_get_pack((string)$task['pack_id']);
    if (!$pack || (string)($pack['manifest']['version'] ?? '') !== (string)$task['pack_version']) {
        throw new RuntimeException('pack_version_unavailable');
    }
    $installed = $db->prepare(
        "SELECT 1 FROM services
         WHERE pack_id = :pack_id AND pack_version = :pack_version AND install_status = 'installed'
         LIMIT 1"
    );
    $installed->execute([':pack_id' => $task['pack_id'], ':pack_version' => $task['pack_version']]);
    if ($installed->fetchColumn() === false) {
        throw new RuntimeException('pack_version_unavailable');
    }
    if (!hub_pack_async_job_is_declared((array)$pack['manifest'], (string)$task['job'])) {
        throw new RuntimeException('job_unavailable');
    }
    $contract = hub_pack_job_contract_from_snapshot($task);
    if (isset($contract['runner']) && ($contract['runner']['accelerator'] ?? null) !== $task['accelerator']) {
        throw new RuntimeException('job_unavailable');
    }

    return $contract;
}

function hub_pack_async_job_is_declared(array $manifest, string $job): bool
{
    foreach ((array)($manifest['async_jobs'] ?? []) as $definition) {
        if (is_array($definition) && ($definition['job'] ?? null) === $job) {
            return true;
        }
    }

    return false;
}

function hub_pack_async_job_max_upload_bytes(array $definition, array $manifest): ?int
{
    if (array_key_exists('max_upload_bytes', $definition)) {
        return hub_pack_async_job_positive_bytes($definition['max_upload_bytes'] ?? null, 1);
    }

    return hub_pack_async_job_positive_bytes($manifest['gateway']['max_upload_mb'] ?? null, 1024 * 1024);
}

function hub_pack_async_job_positive_bytes(mixed $value, int $multiplier): ?int
{
    if (!is_numeric($value) || (float)$value <= 0) {
        return null;
    }
    $bytes = (float)$value * $multiplier;
    if ($bytes < 1 || $bytes > PHP_INT_MAX) {
        return null;
    }

    return (int)floor($bytes);
}

function hub_pack_async_job_contract_names(mixed $values, string $pattern): ?array
{
    if (!is_array($values) || !array_is_list($values)) {
        return null;
    }
    $normalized = [];
    foreach ($values as $value) {
        if (!is_string($value) || !preg_match($pattern, $value) || isset($normalized[$value])) {
            return null;
        }
        $normalized[$value] = true;
    }

    return array_keys($normalized);
}

function hub_pack_is_internal_task(array $manifest): bool
{
    return (string)($manifest['runtime']['kind'] ?? '') === 'internal_task';
}

function hub_pack_internal_container_runner_images(array $manifest): array
{
    $images = [];
    foreach ((array)($manifest['async_jobs'] ?? []) as $definition) {
        if (!is_array($definition) || !isset($definition['runner'])) {
            continue;
        }
        $runner = hub_pack_async_job_runner_contract($definition['runner']);
        if ($runner !== null && ($runner['executor'] ?? '') === 'container') {
            $images[(string)$runner['image']] = true;
        }
    }

    return array_keys($images);
}

function hub_pack_container_runner_build_contract(array $manifest, string $packDir): ?array
{
    $images = hub_pack_internal_container_runner_images($manifest);
    $definition = $manifest['runner_build'] ?? null;
    if ($images === [] && $definition === null) {
        return null;
    }
    $internalTask = hub_pack_is_internal_task($manifest);
    $apiService = (string)($manifest['type'] ?? '') === 'api_service';
    if ((!$internalTask && !$apiService) || !is_array($definition) || array_keys($definition) !== ['context', 'dockerfile', 'image']
        || ($internalTask && (($definition['context'] ?? null) !== 'service' || ($definition['dockerfile'] ?? null) !== 'Dockerfile'))
        || ($apiService && (($definition['context'] ?? null) !== '.' || ($definition['dockerfile'] ?? null) !== 'service/Dockerfile'))
        || !is_string($definition['image'] ?? null) || !in_array($definition['image'], $images, true) || count($images) !== 1) {
        return null;
    }
    $context = realpath($internalTask ? $packDir . '/service' : $packDir);
    $dockerfile = $context === false ? false : realpath($context . '/' . (string)$definition['dockerfile']);
    if ($context === false || $dockerfile === false || !is_dir($context) || !is_file($dockerfile)
        || !str_starts_with($dockerfile, $context . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return ['image' => $definition['image'], 'context' => $context, 'dockerfile' => $dockerfile];
}

function hub_pack_internal_runner_build_contract(array $manifest, string $packDir): ?array
{
    return hub_pack_container_runner_build_contract($manifest, $packDir);
}

function hub_pack_provision_container_runner_image(array $pack, ?callable $commandRunner = null): void
{
    $build = hub_pack_container_runner_build_contract((array)($pack['manifest'] ?? []), (string)($pack['dir'] ?? ''));
    if ($build === null) {
        return;
    }
    $runner = $commandRunner ?? 'hub_run_linux_docker_command';
    $available = static function () use ($runner, $build): bool {
        try {
            $result = $runner(['docker', 'image', 'inspect', '--format', '{{.Id}}', $build['image']], 60);
        } catch (Throwable) {
            return false;
        }

        return is_array($result) && (int)($result['exit_code'] ?? 1) === 0 && trim((string)($result['stdout'] ?? '')) !== '';
    };
    if ($available()) {
        return;
    }
    try {
        $result = $runner(['docker', 'build', '--tag', $build['image'], '--file', $build['dockerfile'], $build['context']], 3600);
    } catch (Throwable) {
        $result = null;
    }
    if (!is_array($result) || (int)($result['exit_code'] ?? 1) !== 0 || !$available()) {
        throw new RuntimeException('internal_runner_image_unavailable');
    }
}

function hub_pack_provision_internal_runner_image(array $pack, ?callable $commandRunner = null): void
{
    hub_pack_provision_container_runner_image($pack, $commandRunner);
}

function hub_validate_pack_manifest(array $manifest, string $packDir): array
{
    $errors = [];
    foreach (['schema_version', 'id', 'name', 'version', 'category', 'type', 'execution_type', 'runtime_level', 'runtime_ready', 'default_mode', 'description', 'runtime', 'gateway', 'hardware', 'queue', 'storage', 'env', 'preflight'] as $field) {
        if (!array_key_exists($field, $manifest)) {
            $errors[] = 'Missing required field: ' . $field;
        }
    }
    if (($manifest['schema_version'] ?? '') !== '0.1') {
        $errors[] = 'Unsupported schema_version.';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', (string)($manifest['id'] ?? ''))) {
        $errors[] = 'Invalid id.';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_]*$/', (string)($manifest['default_mode'] ?? ''))) {
        $errors[] = 'Invalid default_mode.';
    }
    if (!in_array((string)($manifest['execution_type'] ?? ''), ['sync_api', 'async_task', 'long_job'], true)) {
        $errors[] = 'Invalid execution_type.';
    }
    if (!is_string($manifest['runtime_level'] ?? null) || trim((string)$manifest['runtime_level']) === '') {
        $errors[] = 'runtime_level must be a non-empty string.';
    }
    if (!is_bool($manifest['runtime_ready'] ?? null)) {
        $errors[] = 'runtime_ready must be boolean.';
    }

    $runtime = is_array($manifest['runtime'] ?? null) ? $manifest['runtime'] : [];
    if (hub_pack_is_internal_task($manifest)) {
        if ((string)($manifest['execution_type'] ?? '') !== 'async_task') {
            $errors[] = 'internal_task runtime requires async_task execution_type.';
        }
    } else {
        if (!is_file($packDir . '/' . (string)($runtime['compose_file'] ?? ''))) {
            $errors[] = 'runtime.compose_file not found.';
        }
        if ((int)($runtime['default_internal_port'] ?? 0) <= 0) {
            $errors[] = 'runtime.default_internal_port is required.';
        }
    }
    if (array_key_exists('runner_build', $manifest) && hub_pack_container_runner_build_contract($manifest, $packDir) === null) {
        $errors[] = 'container runner_build is invalid.';
    }

    $gateway = is_array($manifest['gateway'] ?? null) ? $manifest['gateway'] : [];
    if (($gateway['invoke_path'] ?? '') === '') {
        $errors[] = 'Missing required gateway field: invoke_path';
    }
    if (!hub_pack_is_internal_task($manifest) && ($gateway['health_path'] ?? '') === '') {
        $errors[] = 'Missing required gateway field: health_path';
    }

    $hardware = is_array($manifest['hardware'] ?? null) ? $manifest['hardware'] : [];
    if (!is_bool($hardware['gpu_required'] ?? null)) {
        $errors[] = 'hardware.gpu_required must be boolean.';
    }

    $preflight = is_array($manifest['preflight'] ?? null) ? $manifest['preflight'] : [];
    if (!is_array($preflight['checks'] ?? null)) {
        $errors[] = 'preflight.checks must be an array.';
    }

    $service = is_array($manifest['service'] ?? null) ? $manifest['service'] : [];
    if (isset($service['default_local_port']) && !hub_validate_service_port((int)$service['default_local_port'])) {
        $errors[] = 'service.default_local_port must be in configured Docker port range.';
    }
    if (!preg_match('/^[A-Z][A-Z0-9_]*$/', (string)($service['local_port_env'] ?? hub_default_port_env((string)($manifest['id'] ?? 'PACK'))))) {
        $errors[] = 'service.local_port_env must be an env var name.';
    }

    if (array_key_exists('async_jobs', $manifest)) {
        $jobs = $manifest['async_jobs'];
        if (!is_array($jobs) || !array_is_list($jobs)) {
            $errors[] = 'async_jobs must be an array.';
        } else {
            $seen = [];
            foreach ($jobs as $definition) {
                $job = is_array($definition) ? (string)($definition['job'] ?? '') : '';
                if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $job) || isset($seen[$job]) || hub_pack_async_job_contract($manifest, $job) === null) {
                    $errors[] = 'async_jobs output contract is invalid.';
                    continue;
                }
                $seen[$job] = true;
            }
        }
    }

    return $errors;
}

function hub_install_pack(PDO $db, string $packId, array|string|null $options = null): array
{
    hub_ensure_default_storage_settings($db);
    $pack = hub_get_pack($packId);
    if (!$pack || $pack['status'] !== 'ok') {
        throw new RuntimeException('HubPack is not available or has validation errors.');
    }

    $legacyIdempotent = is_string($options);
    $options = is_string($options) ? ['service_key' => $options, 'idempotent' => true] : ($options ?? []);
    $manifest = $pack['manifest'];
    $serviceKey = trim((string)($options['service_key'] ?? $manifest['install']['default_service_key'] ?? ($manifest['id'] . '-main')));
    $mode = trim((string)($options['mode'] ?? $manifest['default_mode']));
    $name = trim((string)($options['name'] ?? $manifest['name']));
    $portMode = (string)($options['port_mode'] ?? 'auto');
    $environment = (string)($options['environment'] ?? 'production');
    $hotReload = !empty($options['hot_reload']) ? 1 : 0;
    $idempotent = !empty($options['idempotent']) || $legacyIdempotent;
    $envValues = hub_pack_env_values($manifest, is_array($options['env'] ?? null) ? $options['env'] : []);

    hub_validate_service_instance_input($serviceKey, $mode, $name, $portMode, $environment);
    $existingByKey = hub_get_service_by_key($db, $serviceKey);
    $existingByMode = hub_get_service_by_mode($db, $mode);
    if ($existingByKey && !$idempotent) {
        throw new RuntimeException('service_key already exists.');
    }
    if ($existingByMode && (!$idempotent || ($existingByKey && (int)$existingByMode['id'] !== (int)$existingByKey['id']))) {
        throw new RuntimeException('mode already exists.');
    }
    $existing = $idempotent ? ($existingByKey ?: $existingByMode) : null;

    $isInternalTask = hub_pack_is_internal_task($manifest);
    $runnerBuildRunner = isset($options['runner_build_runner']) && is_callable($options['runner_build_runner']) ? $options['runner_build_runner'] : null;
    if (hub_pack_container_runner_build_contract($manifest, (string)$pack['dir']) !== null
        && (!defined('HUB_TESTING') || HUB_TESTING !== true || $runnerBuildRunner !== null)) {
        hub_pack_provision_container_runner_image($pack, $runnerBuildRunner);
    }
    $localPort = $isInternalTask
        ? null
        : hub_resolve_install_port($db, $manifest, $portMode, $options['local_port'] ?? null, $existing ? (int)$existing['id'] : null);
    $runtimeDir = hub_pack_runtime_dir($db, $serviceKey);
    if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0775, true) && !is_dir($runtimeDir)) {
        throw new RuntimeException('Cannot create service runtime directory.');
    }

    $storage = hub_get_storage_paths($db);
    hub_ensure_pack_storage_dirs($manifest, $serviceKey, $storage, $runtimeDir);
    $composeFile = hub_pack_compose_file($db, $serviceKey);
    $envFile = $runtimeDir . '/.env';
    $portEnv = hub_pack_port_env($manifest);
    file_put_contents($envFile, hub_generate_service_env($manifest, $envValues, $portEnv, (int)($localPort ?? 0), $runtimeDir, $storage));
    file_put_contents(hub_path($composeFile), $isInternalTask ? hub_generate_internal_task_compose($manifest) : hub_generate_pack_compose($pack, $serviceKey, (int)$localPort));
    chmod($envFile, 0664);
    chmod(hub_path($composeFile), 0664);

    $now = hub_now();
    $composeProject = hub_compose_project_for_instance($manifest, $serviceKey);
    $values = [
        ':name' => $name,
        ':mode' => $mode,
        ':type' => (string)$manifest['type'],
        ':internal_url' => $isInternalTask ? 'internal-task:' . (string)$manifest['gateway']['invoke_path'] : 'http://127.0.0.1:' . $localPort . (string)$manifest['gateway']['invoke_path'],
        ':health_url' => $isInternalTask ? 'internal-task:health' : 'http://127.0.0.1:' . $localPort . (string)$manifest['gateway']['health_path'],
        ':compose_project' => $composeProject,
        ':compose_file' => $composeFile,
        ':local_port' => $localPort,
        ':port_mode' => $portMode,
        ':hot_reload' => $hotReload,
        ':environment' => $environment,
        ':execution_type' => (string)$manifest['execution_type'],
        ':environment_json' => json_encode($envValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':pack_id' => (string)$manifest['id'],
        ':pack_version' => (string)$manifest['version'],
        ':service_key' => $serviceKey,
        ':install_status' => 'installed',
        ':runtime_status' => (string)($existing['runtime_status'] ?? $existing['status'] ?? 'stopped'),
        ':status' => (string)($existing['status'] ?? 'stopped'),
        ':created_at' => $now,
        ':updated_at' => $now,
    ];

    if ($existing) {
        $values[':id'] = (int)$existing['id'];
        $stmt = $db->prepare(
            'UPDATE services SET
                name = :name, mode = :mode, type = :type, internal_url = :internal_url, health_url = :health_url,
                compose_project = :compose_project, compose_file = :compose_file, local_port = :local_port,
                port_mode = :port_mode, hot_reload = :hot_reload, environment = :environment,
                execution_type = :execution_type, environment_json = :environment_json, pack_id = :pack_id,
                pack_version = :pack_version, service_key = :service_key, install_status = :install_status,
                runtime_status = :runtime_status, updated_at = :updated_at
             WHERE id = :id'
        );
        unset($values[':status'], $values[':created_at']);
        $stmt->execute($values);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO services
                (name, mode, type, internal_url, health_url, compose_project, compose_file, local_port, port_mode, hot_reload, environment, execution_type, environment_json, pack_id, pack_version, service_key, install_status, runtime_status, enabled, status, created_at, updated_at)
             VALUES
                (:name, :mode, :type, :internal_url, :health_url, :compose_project, :compose_file, :local_port, :port_mode, :hot_reload, :environment, :execution_type, :environment_json, :pack_id, :pack_version, :service_key, :install_status, :runtime_status, 0, :status, :created_at, :updated_at)'
        );
        $stmt->execute($values);
    }

    $service = hub_get_service_by_key($db, $serviceKey);
    if ($service) {
        hub_ensure_service_settings($db, $service);
        hub_write_service_env($db, $service);
    }

    return [
        'pack' => $pack,
        'service' => $service,
    ];
}

function hub_validate_service_instance_input(string $serviceKey, string $mode, string $name, string $portMode, string $environment): void
{
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $serviceKey)) {
        throw new RuntimeException('Invalid service_key.');
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_]*$/', $mode)) {
        throw new RuntimeException('Invalid mode.');
    }
    if ($name === '') {
        throw new RuntimeException('Display name is required.');
    }
    if (!in_array($portMode, ['auto', 'manual'], true)) {
        throw new RuntimeException('Invalid port_mode.');
    }
    if (!in_array($environment, ['production', 'development'], true)) {
        throw new RuntimeException('Invalid environment.');
    }
}

function hub_resolve_install_port(PDO $db, array $manifest, string $portMode, mixed $requestedPort, ?int $existingId): int
{
    if ($portMode === 'manual') {
        $port = (int)$requestedPort;
        if (!hub_validate_service_port($port, $db)) {
            throw new RuntimeException('Invalid local_port.');
        }
        if (hub_local_port_is_used($db, $port, $existingId)) {
            throw new RuntimeException('local_port already exists.');
        }
        return $port;
    }

    if ($existingId) {
        $stmt = $db->prepare('SELECT local_port FROM services WHERE id = :id');
        $stmt->execute([':id' => $existingId]);
        $existingPort = (int)$stmt->fetchColumn();
        if ($existingPort > 0) {
            return $existingPort;
        }
    }

    $defaultPort = (int)($manifest['service']['default_local_port'] ?? 0);
    if ($defaultPort > 0 && hub_port_is_usable_for_install($db, $defaultPort, $existingId)) {
        return $defaultPort;
    }

    return hub_allocate_local_port($db);
}

function hub_local_port_is_used(PDO $db, int $port, ?int $exceptServiceId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM services WHERE local_port = :local_port';
    $params = [':local_port' => $port];
    if ($exceptServiceId !== null) {
        $sql .= ' AND id != :id';
        $params[':id'] = $exceptServiceId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn() > 0;
}

function hub_pack_env_values(array $manifest, array $overrides = []): array
{
    $values = [];
    foreach (($manifest['env'] ?? []) as $item) {
        if (is_array($item) && !empty($item['name'])) {
            $values[(string)$item['name']] = (string)($overrides[$item['name']] ?? $item['default'] ?? '');
        }
    }

    return $values;
}

function hub_generate_service_env(array $manifest, array $envValues, string $portEnv, int $localPort, string $runtimeDir, array $storage): string
{
    $values = array_merge([
        $portEnv => (string)$localPort,
        'SERVICE_DATA_DIR' => $runtimeDir,
        'AIHUB_MODELS_DIR' => $storage['AIHUB_MODELS_DIR'],
        'AIHUB_CACHE_DIR' => $storage['AIHUB_CACHE_DIR'],
        'AIHUB_UPLOADS_DIR' => $storage['AIHUB_UPLOADS_DIR'],
        'AIHUB_RESULTS_DIR' => $storage['AIHUB_RESULTS_DIR'],
        'AIHUB_LOGS_DIR' => $storage['AIHUB_LOGS_DIR'],
    ], hub_pack_storage_runtime_env($manifest), $envValues);

    $lines = [];
    foreach ($values as $key => $value) {
        $lines[] = $key . '=' . $value;
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function hub_pack_storage_runtime_env(array $manifest): array
{
    $paths = [];
    foreach (($manifest['storage']['mounts'] ?? []) as $mount) {
        if (!is_array($mount) || empty($mount['container_path'])) {
            continue;
        }
        $paths[(string)($mount['type'] ?? '')] = (string)$mount['container_path'];
    }

    $modelDir = $paths['models'] ?? '';
    $cacheDir = $paths['cache'] ?? '';
    $serviceDataDir = $paths['service_data'] ?? ($paths['service'] ?? '');
    if ($modelDir === '' || $cacheDir === '' || $serviceDataDir === '') {
        return [];
    }

    return match ((string)($manifest['id'] ?? '')) {
        'ocr-ppocrv5' => [
            'OCR_MODEL_DIR' => $modelDir,
            'OCR_CACHE_DIR' => $cacheDir,
            'OCR_SERVICE_DATA_DIR' => $serviceDataDir,
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $modelDir . '/home',
            'PADDLEOCR_HOME' => $modelDir,
            'PADDLE_PDX_CACHE_HOME' => $modelDir,
            'PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK' => 'True',
        ],
        'yolo' => [
            'YOLO_MODEL_DIR' => $modelDir,
            'YOLO_CACHE_DIR' => $cacheDir,
            'YOLO_SERVICE_DATA_DIR' => $serviceDataDir,
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'ULTRALYTICS_SETTINGS_DIR' => $cacheDir . '/ultralytics',
            'YOLO_CONFIG_DIR' => $cacheDir . '/ultralytics',
        ],
        'yolo-serving' => [
            'YOLO_MODEL_REGISTRY_DIR' => $modelDir,
            'YOLO_CACHE_DIR' => $cacheDir,
            'YOLO_SERVICE_DATA_DIR' => $serviceDataDir,
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'ULTRALYTICS_SETTINGS_DIR' => $cacheDir . '/ultralytics',
            'YOLO_CONFIG_DIR' => $cacheDir . '/ultralytics',
        ],
        'translate-gemma12b' => [
            'TRANSLATE_MODEL_DIR' => '/models/ollama',
            'TRANSLATE_CACHE_DIR' => $cacheDir,
            'TRANSLATE_SERVICE_DATA_DIR' => $serviceDataDir,
            'OLLAMA_BASE_URL' => 'http://ollama:11434',
        ],
        'sam3' => [
            'SAM3_MODEL_DIR' => $modelDir,
            'SAM3_CACHE_DIR' => $cacheDir,
            'SAM3_SERVICE_DATA_DIR' => $serviceDataDir,
            'HF_HOME' => $modelDir . '/huggingface',
            'TORCH_HOME' => $modelDir . '/torch',
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        'whisper-asr' => [
            'WHISPER_MODEL_DIR' => $modelDir,
            'WHISPER_CACHE_DIR' => $cacheDir,
            'WHISPER_SERVICE_DATA_DIR' => $serviceDataDir,
            'HF_HOME' => $modelDir . '/huggingface',
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        'bioclip' => [
            'BIOCLIP_MODEL_DIR' => $modelDir,
            'BIOCLIP_CACHE_DIR' => $cacheDir,
            'BIOCLIP_SERVICE_DATA_DIR' => $serviceDataDir,
            'HF_HOME' => $modelDir . '/huggingface',
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        'tts-voxcpm2' => [
            'VOXCPM2_MODEL_DIR' => $modelDir,
            'VOXCPM2_CACHE_DIR' => $cacheDir,
            'VOXCPM2_SERVICE_DATA_DIR' => $serviceDataDir,
            'HF_HOME' => $modelDir . '/huggingface',
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        'llm-gemma4-12b' => [
            'GEMMA4_CACHE_DIR' => $cacheDir,
            'GEMMA4_SERVICE_DATA_DIR' => $serviceDataDir,
            'HF_HOME' => $modelDir,
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        'structure-ppstructurev3' => [
            'STRUCTURE_MODEL_DIR' => $modelDir,
            'STRUCTURE_CACHE_DIR' => $cacheDir,
            'STRUCTURE_SERVICE_DATA_DIR' => $serviceDataDir,
            'STRUCTURE_DEVICE' => 'cpu',
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $modelDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        default => [],
    };
}

function hub_port_is_usable_for_install(PDO $db, int $port, ?int $exceptServiceId = null): bool
{
    if (!hub_validate_service_port($port, $db) || hub_local_port_is_used($db, $port, $exceptServiceId)) {
        return false;
    }
    if (hub_db_is_runtime_db($db) && hub_port_is_busy($port)) {
        return false;
    }

    return true;
}

function hub_db_file(PDO $db): string
{
    $rows = $db->query('PRAGMA database_list')->fetchAll();
    return (string)($rows[0]['file'] ?? '');
}

function hub_db_is_runtime_db(PDO $db): bool
{
    $path = hub_db_file($db);
    $runtimeDb = HUB_DATA_DIR . '/3waaihub.sqlite';

    return $path !== '' && realpath($path) === realpath($runtimeDb);
}

function hub_pack_runtime_base_dir(PDO $db): string
{
    if (hub_db_is_runtime_db($db)) {
        return HUB_SERVICE_DIR;
    }

    $dbFile = hub_db_file($db);
    $suffix = substr(sha1($dbFile !== '' ? $dbFile : spl_object_id($db)), 0, 12);

    return HUB_DATA_DIR . '/test_services/' . $suffix;
}

function hub_pack_runtime_dir(PDO $db, string $serviceKey): string
{
    return hub_pack_runtime_base_dir($db) . '/' . $serviceKey;
}

function hub_pack_compose_file(PDO $db, string $serviceKey): string
{
    if (hub_db_is_runtime_db($db)) {
        return 'data/services/' . $serviceKey . '/docker-compose.generated.yml';
    }

    return 'data/test_services/' . basename(hub_pack_runtime_base_dir($db)) . '/' . $serviceKey . '/docker-compose.generated.yml';
}

function hub_service_key_requests_gpu(string $serviceKey): bool
{
    return preg_match('/(^|[-_])gpu($|[-_])/', strtolower($serviceKey)) === 1;
}

function hub_pack_requests_gpu(array $manifest, string $serviceKey = ''): bool
{
    if (!empty($manifest['hardware']['gpu_required'])) {
        return true;
    }
    if (($manifest['id'] ?? '') === 'ocr-ppocrv5') {
        return hub_service_key_requests_gpu($serviceKey);
    }
    if (($manifest['id'] ?? '') === 'yolo-serving') {
        return $serviceKey === 'yolo-gpu0' || hub_service_key_requests_gpu($serviceKey);
    }

    foreach (($manifest['env'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = strtoupper((string)($item['name'] ?? ''));
        if ($name !== 'USE_GPU' && !str_ends_with($name, '_USE_GPU')) {
            continue;
        }
        $default = strtolower(trim((string)($item['default'] ?? '0')));

        return !in_array($default, ['', '0', 'false', 'no', 'off'], true);
    }

    return false;
}

function hub_generate_pack_compose(array $pack, string $serviceKey, int $localPort): string
{
    $manifest = $pack['manifest'];
    if (($manifest['id'] ?? '') === 'translate-gemma12b') {
        return hub_generate_translate_gemma_compose($pack, $serviceKey, $localPort);
    }
    if (($manifest['id'] ?? '') === 'llm-gemma4-12b') {
        return hub_generate_llm_gemma4_compose($pack, $serviceKey, $localPort);
    }

    $composeService = ($manifest['id'] ?? '') === 'hello' && $serviceKey === 'hello-main' ? 'hello' : $serviceKey;
    $containerName = ($manifest['id'] ?? '') === 'hello' && $serviceKey === 'hello-main' ? '3waaihub-hello' : '3waaihub-' . $serviceKey;
    $portEnv = hub_pack_port_env($manifest);
    $buildContext = $pack['dir'] . '/service';
    $dockerfile = '';
    if (($manifest['id'] ?? '') === 'whisper-asr') {
        $buildContext = $pack['dir'];
        $dockerfile = "      dockerfile: service/Dockerfile\n";
    }
    $imageTag = ($manifest['id'] ?? '') === 'whisper-asr'
        ? '3waaihub/whisper-asr:' . (string)($manifest['version'] ?? 'latest')
        : hub_pack_image_tag($serviceKey, (string)($manifest['version'] ?? 'latest'));

    $compose = "services:\n"
        . "  {$composeService}:\n"
        . "    image: {$imageTag}\n"
        . "    build:\n"
        . "      context: {$buildContext}\n"
        . $dockerfile
        . "    container_name: {$containerName}\n"
        . "    env_file:\n"
        . "      - .env\n"
        . "    ports:\n"
        . '      - "127.0.0.1:${' . $portEnv . ':-' . $localPort . '}:' . (int)$manifest['runtime']['default_internal_port'] . '"' . "\n"
        . "    restart: unless-stopped\n";

    if (hub_pack_requests_gpu($manifest, $serviceKey)) {
        $visibleDevices = (($manifest['id'] ?? '') === 'yolo-serving' && $serviceKey === 'yolo-gpu0') ? '0' : 'all';
        $compose .= "    gpus: all\n"
            . "    environment:\n"
            . '      NVIDIA_VISIBLE_DEVICES: "${GPU_VISIBLE_DEVICES:-' . $visibleDevices . '}"' . "\n"
            . '      NVIDIA_DRIVER_CAPABILITIES: "compute,utility"' . "\n";
    }

    $volumes = hub_generate_pack_storage_volumes($manifest, $serviceKey);
    if ($volumes) {
        $compose .= "    volumes:\n";
        foreach ($volumes as $volume) {
            $compose .= '      - "' . $volume . '"' . "\n";
        }
    }

    return $compose;
}

function hub_generate_translate_gemma_compose(array $pack, string $serviceKey, int $localPort): string
{
    $manifest = $pack['manifest'];
    $portEnv = hub_pack_port_env($manifest);
    $buildContext = $pack['dir'] . '/service';
    $imageTag = hub_pack_image_tag($serviceKey, (string)($manifest['version'] ?? 'latest'));
    $internalPort = (int)($manifest['runtime']['default_internal_port'] ?? 8000);

    return "services:\n"
        . "  ollama:\n"
        . "    image: ollama/ollama:latest\n"
        . "    container_name: 3waaihub-{$serviceKey}-ollama\n"
        . "    env_file:\n"
        . "      - .env\n"
        . "    environment:\n"
        . '      OLLAMA_HOST: "0.0.0.0:11434"' . "\n"
        . '      NVIDIA_VISIBLE_DEVICES: "${GPU_VISIBLE_DEVICES:-all}"' . "\n"
        . '      NVIDIA_DRIVER_CAPABILITIES: "compute,utility"' . "\n"
        . "    gpus: all\n"
        . "    restart: unless-stopped\n"
        . "    volumes:\n"
        . '      - "${AIHUB_MODELS_DIR}/ollama:/root/.ollama"' . "\n"
        . "  translator-api:\n"
        . "    image: {$imageTag}\n"
        . "    build:\n"
        . "      context: {$buildContext}\n"
        . "    container_name: 3waaihub-{$serviceKey}\n"
        . "    env_file:\n"
        . "      - .env\n"
        . "    depends_on:\n"
        . "      - ollama\n"
        . "    ports:\n"
        . '      - "127.0.0.1:${' . $portEnv . ':-' . $localPort . '}:' . $internalPort . '"' . "\n"
        . "    restart: unless-stopped\n"
        . "    volumes:\n"
        . '      - "${AIHUB_CACHE_DIR}/translate:/cache/translate"' . "\n"
        . '      - "${SERVICE_DATA_DIR}:/data/service"' . "\n";
}

function hub_generate_llm_gemma4_compose(array $pack, string $serviceKey, int $localPort): string
{
    $manifest = $pack['manifest'];
    $portEnv = hub_pack_port_env($manifest);
    $buildContext = $pack['dir'] . '/service';
    $imageTag = hub_pack_image_tag($serviceKey, (string)($manifest['version'] ?? 'latest'));
    $internalPort = (int)($manifest['runtime']['default_internal_port'] ?? 8000);

    return "services:\n"
        . "  vllm:\n"
        . "    image: vllm/vllm-openai:latest\n"
        . "    container_name: 3waaihub-{$serviceKey}-vllm\n"
        . "    env_file:\n"
        . "      - .env\n"
        . "    entrypoint: [\"/bin/bash\", \"-lc\"]\n"
        . "    command:\n"
        . "      - >-\n"
        . '        exec vllm serve "${VLLM_MODEL}"' . "\n"
        . '        --served-model-name "${VLLM_SERVED_MODEL_NAME:-gemma4-12b}"' . "\n"
        . "        --host 0.0.0.0\n"
        . "        --port 8000\n"
        . '        --max-model-len "${VLLM_MAX_MODEL_LEN:-16384}"' . "\n"
        . '        --gpu-memory-utilization "${VLLM_GPU_MEMORY_UTILIZATION:-0.64}"' . "\n"
        . '        --max-num-seqs "${VLLM_MAX_NUM_SEQS:-1}"' . "\n"
        . "    gpus: all\n"
        . "    environment:\n"
        . '      NVIDIA_VISIBLE_DEVICES: "${GPU_VISIBLE_DEVICES:-all}"' . "\n"
        . '      NVIDIA_DRIVER_CAPABILITIES: "compute,utility"' . "\n"
        . "    restart: unless-stopped\n"
        . "    volumes:\n"
        . '      - "${AIHUB_MODELS_DIR}/huggingface:/root/.cache/huggingface"' . "\n"
        . '      - "${AIHUB_CACHE_DIR}/gemma4:/cache/gemma4"' . "\n"
        . "  chat-api:\n"
        . "    image: {$imageTag}\n"
        . "    build:\n"
        . "      context: {$buildContext}\n"
        . "    container_name: 3waaihub-{$serviceKey}\n"
        . "    env_file:\n"
        . "      - .env\n"
        . "    depends_on:\n"
        . "      - vllm\n"
        . "    ports:\n"
        . '      - "127.0.0.1:${' . $portEnv . ':-' . $localPort . '}:' . $internalPort . '"' . "\n"
        . "    restart: unless-stopped\n"
        . "    volumes:\n"
        . '      - "${AIHUB_CACHE_DIR}/gemma4:/cache/gemma4"' . "\n"
        . '      - "${SERVICE_DATA_DIR}:/data/service"' . "\n"
        . '      - "${AIHUB_UPLOADS_DIR}/photo:/data/photo:ro"' . "\n";
}

function hub_generate_internal_task_compose(array $manifest): string
{
    return "# 3waAIHub internal_task runtime\n"
        . "# pack_id=" . (string)($manifest['id'] ?? '') . "\n"
        . "# no Docker service is required; task_worker.php executes this orchestrator.\n";
}

function hub_pack_image_tag(string $serviceKey, string $packVersion): string
{
    $tag = preg_replace('/[^A-Za-z0-9_.-]/', '-', $packVersion) ?: 'latest';
    return '3waaihub-' . $serviceKey . ':' . $tag;
}

function hub_ensure_pack_storage_dirs(array $manifest, string $serviceKey, array $storage, ?string $serviceDir = null): void
{
    $prefix = [
        'models' => $storage['AIHUB_MODELS_DIR'],
        'cache' => $storage['AIHUB_CACHE_DIR'],
        'uploads' => $storage['AIHUB_UPLOADS_DIR'],
        'results' => $storage['AIHUB_RESULTS_DIR'],
        'service' => $serviceDir ?? HUB_SERVICE_DIR . '/' . $serviceKey,
        'service_data' => $serviceDir ?? HUB_SERVICE_DIR . '/' . $serviceKey,
    ];
    foreach (($manifest['storage']['mounts'] ?? []) as $mount) {
        if (!is_array($mount) || empty($prefix[$mount['type'] ?? ''])) {
            continue;
        }
        $hostSubdir = trim((string)($mount['host_subdir'] ?? ''), '/');
        $dir = $prefix[(string)$mount['type']] . ($hostSubdir !== '' ? '/' . $hostSubdir : '');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create pack storage directory: ' . $dir);
        }
    }
}

function hub_generate_pack_storage_volumes(array $manifest, string $serviceKey): array
{
    $prefix = [
        'models' => '${AIHUB_MODELS_DIR}',
        'cache' => '${AIHUB_CACHE_DIR}',
        'uploads' => '${AIHUB_UPLOADS_DIR}',
        'results' => '${AIHUB_RESULTS_DIR}',
        'service' => '${SERVICE_DATA_DIR}',
        'service_data' => '${SERVICE_DATA_DIR}',
    ];
    $volumes = [];
    foreach (($manifest['storage']['mounts'] ?? []) as $mount) {
        if (!is_array($mount) || empty($prefix[$mount['type'] ?? '']) || empty($mount['container_path'])) {
            continue;
        }
        $hostSubdir = trim((string)($mount['host_subdir'] ?? $serviceKey), '/');
        $host = $prefix[(string)$mount['type']] . ($hostSubdir !== '' ? '/' . $hostSubdir : '');
        $mode = !empty($mount['read_only']) ? ':ro' : '';
        $volumes[] = $host . ':' . (string)$mount['container_path'] . $mode;
    }

    return $volumes;
}

function hub_pack_port_env(array $manifest): string
{
    return (string)($manifest['service']['local_port_env'] ?? hub_default_port_env((string)$manifest['id']));
}

function hub_default_port_env(string $packId): string
{
    return strtoupper(str_replace('-', '_', $packId)) . '_LOCAL_PORT';
}

function hub_compose_project_for_instance(array $manifest, string $serviceKey): string
{
    if (($manifest['install']['default_service_key'] ?? '') === $serviceKey && !empty($manifest['install']['compose_project'])) {
        return (string)$manifest['install']['compose_project'];
    }

    return '3waaihub_' . str_replace('-', '_', $serviceKey);
}
