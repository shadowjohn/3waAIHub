# VoxCPM2 Three-Mode Real-Inference Smoke

Use this runbook only with a short WAV for which the API-member owner has recorded consent. It validates Whisper GPU inference first, then the authenticated TTS Playground flow. Do not commit the WAV, a token, a transcript, model files, generated audio, or copied logs.

Run the shell steps from the 3waAIHub checkout with `bash`. The Docker user must already have Docker socket access.

## 1. Resolve Installed Services

In Service Settings, enable both installed services. Set VoxCPM2 `VOXCPM2_REAL_INFERENCE=1`, restart it, and keep the Playground real-inference checkbox selected. The resolver below stops when that persisted setting or Whisper `USE_GPU=1` is absent; use the printed `TTS_SERVICE_ID` in `admin/service_settings.php?service_id=<id>` to correct it, then rerun this step.

```bash
set -euo pipefail
: "${CONSENTED_WAV:?export CONSENTED_WAV=/absolute/path/to/a-consented.wav}"
test -r "$CONSENTED_WAV"

RUNTIME_VARS="$(php <<'PHP'
<?php
require 'app/bootstrap.php';

$db = hub_db();
$definitions = [
    'ASR' => ['mode' => 'asr', 'pack_id' => 'whisper-asr'],
    'TTS' => ['mode' => 'tts', 'pack_id' => 'tts-voxcpm2'],
];
$services = [];
foreach ($definitions as $name => $definition) {
    $service = hub_get_service_by_mode($db, $definition['mode']);
    if (!$service || (string)$service['pack_id'] !== $definition['pack_id']
        || (string)$service['install_status'] !== 'installed' || (int)$service['enabled'] !== 1) {
        throw new RuntimeException($name . ' must be an enabled installed ' . $definition['pack_id'] . ' service.');
    }
    $services[$name] = $service;
}

$asrSettings = hub_list_service_settings($db, (int)$services['ASR']['id']);
$ttsSettings = hub_list_service_settings($db, (int)$services['TTS']['id']);
$values = [
    'ASR_SERVICE_ID' => (string)$services['ASR']['id'],
    'ASR_PROJECT' => (string)$services['ASR']['compose_project'],
    'ASR_COMPOSE' => hub_path((string)$services['ASR']['compose_file']),
    'ASR_ENV' => dirname(hub_path((string)$services['ASR']['compose_file'])) . '/.env',
    'ASR_HEALTH_URL' => (string)$services['ASR']['health_url'],
    'ASR_INFER_URL' => (string)$services['ASR']['internal_url'],
    'ASR_USE_GPU' => (string)($asrSettings['USE_GPU']['value'] ?? ''),
    'TTS_SERVICE_ID' => (string)$services['TTS']['id'],
    'TTS_PROJECT' => (string)$services['TTS']['compose_project'],
    'TTS_COMPOSE' => hub_path((string)$services['TTS']['compose_file']),
    'TTS_ENV' => dirname(hub_path((string)$services['TTS']['compose_file'])) . '/.env',
    'TTS_HEALTH_URL' => (string)$services['TTS']['health_url'],
    'TTS_REAL_INFERENCE' => (string)($ttsSettings['VOXCPM2_REAL_INFERENCE']['value'] ?? ''),
];
foreach ($values as $key => $value) {
    printf("%s=%s\n", $key, escapeshellarg($value));
}
PHP
)"
eval "$RUNTIME_VARS"

[ "$ASR_USE_GPU" = '1' ] || { echo 'Whisper USE_GPU must be persisted as 1.' >&2; exit 1; }
[ "$TTS_REAL_INFERENCE" = '1' ] || { echo "Set VOXCPM2_REAL_INFERENCE=1 for service $TTS_SERVICE_ID first." >&2; exit 1; }
test -f "$ASR_COMPOSE" && test -f "$ASR_ENV" && test -f "$TTS_COMPOSE" && test -f "$TTS_ENV"
```

The document deliberately derives compose files, projects, and loopback health URLs from `app/bootstrap.php` and the database. Do not replace them with a host-specific path or public URL.

## 2. Fail Early On Docker GPU Access

```bash
command -v docker >/dev/null
docker info --format 'Docker server {{.ServerVersion}}'
docker compose version
command -v nvidia-smi >/dev/null
nvidia-smi -L
docker run --rm --gpus all nvidia/cuda:12.9.0-base-ubuntu24.04 nvidia-smi -L

grep -Eq '^[[:space:]]*gpus:[[:space:]]+all[[:space:]]*$' "$ASR_COMPOSE"
grep -Eq '^[[:space:]]*gpus:[[:space:]]+all[[:space:]]*$' "$TTS_COMPOSE"
ASR_COMPOSE_CMD=(docker compose --env-file "$ASR_ENV" -p "$ASR_PROJECT" -f "$ASR_COMPOSE")
TTS_COMPOSE_CMD=(docker compose --env-file "$TTS_ENV" -p "$TTS_PROJECT" -f "$TTS_COMPOSE")
"${ASR_COMPOSE_CMD[@]}" config -q
"${TTS_COMPOSE_CMD[@]}" config -q
```

The NVIDIA container command may pull its image once. A failure here is a Docker/NVIDIA runtime problem; fix it before starting Whisper. The CPU fallback inside faster-whisper happens only after a GPU-capable container starts and is not a replacement for a working Docker GPU runtime.

The persisted `USE_GPU=1` setting and the TTS GPU-required pack must both have produced a generated compose entry of `gpus: all`; the preceding `grep` commands make that a pre-start requirement.

## 3. Rebuild And Verify GPU-Only Whisper ASR

`WHISPER_REAL_INFERENCE=1` is required. Keep the response files temporary: this check prints only metadata and transcript byte length, never the transcript itself.

Run this block and the TTS check in the same Bash process. Its `EXIT` trap preserves the command failure code, prints local compose diagnostics on failure, and stops only services this smoke started.

```bash
ASR_STARTED_BY_SMOKE=0
TTS_STARTED_BY_SMOKE=0
ASR_HEALTH_JSON=''
ASR_RESPONSE_JSON=''
TTS_HEALTH_JSON=''

cleanup() {
    local status=$?
    trap - EXIT INT TERM
    if [ "$status" -ne 0 ]; then
        printf 'Smoke failed; local compose status and log tails follow.\n' >&2
        "${ASR_COMPOSE_CMD[@]}" ps || true
        "${ASR_COMPOSE_CMD[@]}" logs --tail=200 || true
        "${TTS_COMPOSE_CMD[@]}" ps || true
        "${TTS_COMPOSE_CMD[@]}" logs --tail=200 || true
    fi
    if [ "$TTS_STARTED_BY_SMOKE" = '1' ]; then
        "${TTS_COMPOSE_CMD[@]}" stop || true
    fi
    if [ "$ASR_STARTED_BY_SMOKE" = '1' ]; then
        "${ASR_COMPOSE_CMD[@]}" stop || true
    fi
    rm -f "${ASR_HEALTH_JSON:-}" "${ASR_RESPONSE_JSON:-}" "${TTS_HEALTH_JSON:-}"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

ASR_WAS_RUNNING="$("${ASR_COMPOSE_CMD[@]}" ps --status running -q)"
TTS_WAS_RUNNING="$("${TTS_COMPOSE_CMD[@]}" ps --status running -q)"
if [ -z "$ASR_WAS_RUNNING" ]; then ASR_STARTED_BY_SMOKE=1; fi

"${ASR_COMPOSE_CMD[@]}" build --progress=plain
"${ASR_COMPOSE_CMD[@]}" up -d

if [ -z "$TTS_WAS_RUNNING" ]; then
    TTS_STARTED_BY_SMOKE=1
    "${TTS_COMPOSE_CMD[@]}" build --progress=plain
    "${TTS_COMPOSE_CMD[@]}" up -d
fi

ASR_HEALTH_JSON="$(mktemp)"
ASR_RESPONSE_JSON="$(mktemp)"
for _ in $(seq 1 30); do
    if curl --fail --silent --show-error --connect-timeout 5 --max-time 20 "$ASR_HEALTH_URL" >"$ASR_HEALTH_JSON"; then break; fi
    sleep 2
done
test -s "$ASR_HEALTH_JSON"

php -r '
$payload = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (($payload["ok"] ?? false) !== true || ($payload["ready"] ?? false) !== true
    || ($payload["real_inference"] ?? false) !== true) {
    fwrite(STDERR, "Whisper health is not real-inference ready.\n");
    exit(1);
}
echo "Whisper health PASS\n";
' "$ASR_HEALTH_JSON"

ASR_HTTP_STATUS="$(curl --silent --show-error --connect-timeout 10 --max-time 180 --output "$ASR_RESPONSE_JSON" --write-out '%{http_code}' \
    -F "audio=@${CONSENTED_WAV};type=audio/wav" \
    -F real_inference=1 \
    "$ASR_INFER_URL")"
php -r '
$status = (int)$argv[1];
$payload = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$device = is_array($payload["device"] ?? null) ? $payload["device"] : [];
$text = trim((string)($payload["text"] ?? ""));
if ($status < 200 || $status >= 300 || ($payload["ok"] ?? false) !== true
    || ($payload["mock"] ?? null) !== false || $text === ""
    || ($device["effective"] ?? "") !== "cuda" || ($device["fallback_used"] ?? true) !== false) {
    fwrite(STDERR, "Whisper GPU real-inference contract failed.\n");
    exit(1);
}
printf("Whisper GPU real-inference PASS (%d transcript bytes).\n", strlen($text));
' "$ASR_HTTP_STATUS" "$ASR_RESPONSE_JSON"
```

`effective=cpu` with `fallback_used=true` proves the fallback diagnostic works, but it is a **failure** for this GPU smoke. Collect the diagnostics below, resolve CUDA/Docker/model issues, and repeat the GPU check; do not mark a CPU result or any mock result as a pass.

## 4. Run The Three-Mode Playground

Confirm the installed TTS service is healthy and has its real dependencies before using the browser flow:

```bash
TTS_HEALTH_JSON="$(mktemp)"
curl --fail --silent --show-error --connect-timeout 5 --max-time 20 "$TTS_HEALTH_URL" >"$TTS_HEALTH_JSON"
php -r '
$payload = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (($payload["ok"] ?? false) !== true || ($payload["ready"] ?? false) !== true
    || ($payload["real_inference"] ?? false) !== true || ($payload["dependency_available"] ?? false) !== true) {
    fwrite(STDERR, "VoxCPM2 health is not real-inference ready.\n");
    exit(1);
}
echo "VoxCPM2 health PASS\n";
' "$TTS_HEALTH_JSON"
TTS_CONTAINER_ID="$("${TTS_COMPOSE_CMD[@]}" ps --status running -q | sed -n '1p')"
test -n "$TTS_CONTAINER_ID"
docker exec "$TTS_CONTAINER_ID" python3 -c 'import torch; assert torch.cuda.is_available(); print(torch.cuda.get_device_name(0))'
rm -f "$TTS_HEALTH_JSON"
```

`tts-voxcpm2` health does not expose an effective CUDA device, and its slim Python image does not provide `nvidia-smi`. Therefore the exact PyTorch probe above is mandatory: it proves the running TTS container, rather than only the host, can use CUDA and names its device. A failing probe fails this real-inference smoke even when the health response and `mock: false` look good.

1. Sign in and open `admin/playground.php?mode=tts`. Select the installed TTS service, enter a fresh API token with `tts` permission only in the Bearer Token field, and leave `真實推論` selected. The field is request-local; do not paste it into shell history, screenshots, or deployment notes.
2. In **Voice Profile**, upload the same consented WAV, choose the correct consent type, and select the resulting profile. A first upload creates an owner-only profile; an identical upload by the same member may say it reused the cache. Verify the displayed ASR status becomes `ready / draft`. For `pending`, wait and reload; for `failed`, inspect ASR diagnostics and use `重試字幕` only after the cause is fixed.
3. Review and edit the drafted text manually, then click `確認字幕`. Verify the profile changes to `ready / confirmed`. Until then Ultimate Clone must fail with `voice_profile_transcript_unconfirmed`; this is a required ownership and transcript-confirmation guard, not a TTS fault.
4. Enter the desired output text, choose the confirmed profile, and click `三種比較`. The Playground invokes `design`, `clone`, and `ultimate_clone` sequentially. For each result, require a 2xx HTTP status, `success: true`, `mock: false`, `real_inference_requested: true`, a `/artifacts/tts_*.wav` value, and an independently playable/downloadable WAV player. Any 5xx, missing player, or mock response fails the smoke.

Basic and Ultimate clone fields follow the [official VoxCPM2 model card](https://huggingface.co/openbmb/VoxCPM2). Whisper CUDA requirements follow the [official faster-whisper CUDA 12/cuDNN 9 notes](https://github.com/SYSTRAN/faster-whisper/blob/master/README.md).

## 5. Failure Evidence And Cleanup

Keep evidence local and redact private audio, transcripts, and tokens before sharing it.

```bash
"${ASR_COMPOSE_CMD[@]}" ps
"${ASR_COMPOSE_CMD[@]}" logs --tail=200
"${TTS_COMPOSE_CMD[@]}" ps
"${TTS_COMPOSE_CMD[@]}" logs --tail=200

php <<'PHP'
<?php
require 'app/bootstrap.php';
$db = hub_db();
foreach (['asr', 'tts'] as $mode) {
    $service = hub_get_service_by_mode($db, $mode);
    if (!$service) {
        continue;
    }
    printf("%s service=%d status=%s runtime=%s health=%s\n", $mode, $service['id'], $service['status'], $service['runtime_status'], $service['health_url']);
    foreach (hub_list_service_logs($db, (int)$service['id'], 5) as $log) {
        printf("  %s exit=%d %s\n", $log['action'], $log['exit_code'], $log['created_at']);
    }
}
PHP

cleanup
```

The cleanup stops only ASR or TTS containers that were not running when this procedure began. It does not stop pre-existing containers, does not remove volumes, and does not delete the configured model/cache/service-data mounts. The first real inference may download model files into those persistent mounts; retain them for the next smoke.
