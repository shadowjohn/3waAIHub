# Edge TTS Real Smoke

Run this procedure from the 3waAIHub checkout. It intentionally uses the
public API and the normal workers; do not call the Pack runner or Docker
container directly. This is an experimental third-party online service. Submit
only the short non-confidential Chinese sentence below, and do not capture its
text, token, task URLs, query parameters, or generated audio in shared logs.

## Install And Worker Checks

As a system administrator, open `admin/packs.php`, install `edge-tts`, and
enable its `edge_tts` service. Grant the smoke token only these modes:
`edge_tts`, `task_status`, `task_result`, `artifact`, and
`task_artifacts_ack`. The API token must already be held by the environment;
do not paste it into this document, a command history, or a captured terminal
transcript.

Build installation runs through the existing command worker. Confirm its image
and offline runner check before submitting the real request:

```bash
php scripts/command_worker.php --limit=5
docker image inspect --format '{{.Id}}' 3waaihub/edge-tts:0.2.0
bash packs/edge-tts/service/test_egress_firewall.sh
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

The task worker must also be available. This procedure uses one foreground run
after submission; a configured scheduler can perform the same work instead.

```bash
php scripts/task_worker.php --limit=1
```

## Submit And Poll

Use an `api.php` base URL and an environment-held token. This posts
`api.php?mode=edge_tts`. Do not enable shell tracing. The JSON Unicode escapes
are a short non-confidential Chinese sentence and keep this runbook ASCII.

```bash
set -euo pipefail
set +x
: "${AIHUB_EDGE_TTS_BASE_URL:?Set this to the HTTPS api.php URL}"
: "${AIHUB_EDGE_TTS_TOKEN:?Export the approved smoke token}"
WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

curl --fail --silent --show-error --request POST \
  -H "Authorization: Bearer $AIHUB_EDGE_TTS_TOKEN" \
  -H 'Content-Type: application/json' \
  --data '{"text":"\u9019\u662f\u4e00\u6bb5\u975e\u6a5f\u5bc6\u7684\u4e2d\u6587\u5408\u6210\u3002","voice":"zh-TW-HsiaoChenNeural","include_subtitles":true}' \
  --output "$WORKDIR/submit.json" \
  "$AIHUB_EDGE_TTS_BASE_URL?mode=edge_tts"

TASK_ID="$(php -r '
  $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  $id = $value["task_id"] ?? null;
  if (!is_int($id) || $id < 1) { throw new RuntimeException("task_id missing"); }
  echo $id;
' "$WORKDIR/submit.json")"

php scripts/task_worker.php --limit=1

STATUS=""
for attempt in $(seq 1 60); do
  curl --fail --silent --show-error \
    -H "Authorization: Bearer $AIHUB_EDGE_TTS_TOKEN" \
    --output "$WORKDIR/status.json" \
    "$AIHUB_EDGE_TTS_BASE_URL?mode=task_status&task_id=$TASK_ID"
  STATUS="$(php -r '
    $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $status = $value["status"] ?? null;
    if (!is_string($status)) { throw new RuntimeException("task status missing"); }
    echo $status;
  ' "$WORKDIR/status.json")"
  case "$STATUS" in
    success) break ;;
    failed|cancelled|timed_out) exit 1 ;;
  esac
  sleep 2
done
test "$STATUS" = success
```

If the task fails with `upstream_unavailable`, treat that as a valid failed-path
check when the outbound firewall blocks the provider. Do not allow general
Internet access or choose a substitute provider. Before a controlled retry,
the only approved provider egress is `speech.platform.bing.com:443`.

## Download, Validate, And Acknowledge

Read the result only after `success`, then select the owned `generated_audio`,
`subtitle_vtt`, `subtitle_srt`, and `speech_timeline` artifacts. Do not print
the result or the caption contents.

```bash
curl --fail --silent --show-error \
  -H "Authorization: Bearer $AIHUB_EDGE_TTS_TOKEN" \
  --output "$WORKDIR/result.json" \
  "$AIHUB_EDGE_TTS_BASE_URL?mode=task_result&task_id=$TASK_ID"

read -r AUDIO_ID AUDIO_SHA256 VTT_ID SRT_ID TIMELINE_ID <<EOF
$(php -r '
  $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  $found = [];
  foreach (($value["result"]["artifacts"] ?? []) as $artifact) {
    $type = $artifact["type"] ?? null;
    $id = $artifact["id"] ?? null;
    if (!in_array($type, ["generated_audio", "subtitle_vtt", "subtitle_srt", "speech_timeline"], true)) {
      continue;
    }
    if (!is_int($id) || $id < 1 || array_key_exists($type, $found)) {
      throw new RuntimeException("required artifact is invalid");
    }
    $found[$type] = $id;
    if ($type === "generated_audio") {
      $sha256 = $artifact["sha256"] ?? null;
      if (preg_match("/^[a-f0-9]{64}$/", (string) $sha256) !== 1) {
        throw new RuntimeException("generated_audio hash missing");
      }
      $found["generated_audio_sha256"] = $sha256;
    }
  }
  foreach (["generated_audio", "subtitle_vtt", "subtitle_srt", "speech_timeline", "generated_audio_sha256"] as $required) {
    if (!array_key_exists($required, $found)) {
      throw new RuntimeException("required artifact missing");
    }
  }
  echo $found["generated_audio"], " ", $found["generated_audio_sha256"], " ", $found["subtitle_vtt"], " ", $found["subtitle_srt"], " ", $found["speech_timeline"];
' "$WORKDIR/result.json")
EOF

MP3="$WORKDIR/generated_audio.mp3"
VTT="$WORKDIR/subtitle.vtt"
SRT="$WORKDIR/subtitle.srt"
TIMELINE="$WORKDIR/speech_timeline.json"
for ARTIFACT_ID_PATH in "$AUDIO_ID:$MP3" "$VTT_ID:$VTT" "$SRT_ID:$SRT" "$TIMELINE_ID:$TIMELINE"; do
  ARTIFACT_ID="${ARTIFACT_ID_PATH%%:*}"
  ARTIFACT_PATH="${ARTIFACT_ID_PATH#*:}"
  curl --fail --silent --show-error \
    -H "Authorization: Bearer $AIHUB_EDGE_TTS_TOKEN" \
    --output "$ARTIFACT_PATH" \
    "$AIHUB_EDGE_TTS_BASE_URL?mode=artifact&artifact_id=$ARTIFACT_ID"
done
test "$(sha256sum "$MP3" | awk '{print $1}')" = "$AUDIO_SHA256"
test "$(ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of default=nokey=1:noprint_wrappers=1 "$MP3")" = mp3
DURATION="$(ffprobe -v error -show_entries format=duration -of default=nokey=1:noprint_wrappers=1 "$MP3")"
php -r 'exit(is_numeric($argv[1]) && (float) $argv[1] > 0.0 ? 0 : 1);' "$DURATION"
grep -Fqx 'WEBVTT' "$VTT"
grep -Eq '^1$' "$SRT"
php -r '
  $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (!is_array($value)) {
    throw new RuntimeException("timeline must be a JSON object");
  }
  foreach (["version", "unit", "duration_ms", "sentences", "words"] as $key) {
    if (!array_key_exists($key, $value)) {
      throw new RuntimeException("timeline key missing");
    }
  }
' "$TIMELINE"

for ARTIFACT_ID in "$AUDIO_ID" "$VTT_ID" "$SRT_ID" "$TIMELINE_ID"; do
  curl --fail --silent --show-error --request POST \
    -H "Authorization: Bearer $AIHUB_EDGE_TTS_TOKEN" \
    -F "task_id=$TASK_ID" \
    -F "artifact_id=$ARTIFACT_ID" \
    --output "$WORKDIR/ack.json" \
    "$AIHUB_EDGE_TTS_BASE_URL?mode=task_artifacts_ack"
  php -r '
    $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    if (($value["ok"] ?? false) !== true || empty($value["acknowledged_at"])) {
      throw new RuntimeException("artifact acknowledgement failed");
    }
  ' "$WORKDIR/ack.json"
done
```

## CPU Postcondition

Edge TTS is CPU-only. There is no GPU lease expectation. After completion,
require that this smoke task owns no leased `gpu:0` resource:

```bash
TASK_ID="$TASK_ID" php <<'PHP'
<?php
require 'app/bootstrap.php';
$taskId = (int) getenv('TASK_ID');
$stmt = hub_db()->prepare(
    'SELECT COUNT(*) FROM runtime_resource_leases AS leases
     INNER JOIN runtime_runs AS runs ON runs.run_id = leases.runtime_run_id
     WHERE runs.task_id = :task_id
       AND leases.resource_key = :resource_key
       AND leases.state = :state'
);
$stmt->execute([':task_id' => $taskId, ':resource_key' => 'gpu:0', ':state' => 'leased']);
if ((int) $stmt->fetchColumn() !== 0) {
    throw new RuntimeException('Edge TTS smoke must not own a GPU lease');
}
PHP
```

Record only task and artifact IDs, terminal status, the audio SHA-256 match,
MP3 validation boolean, VTT/SRT/timeline validation booleans, acknowledgement
booleans, and no-GPU-lease result. Redact the token, submitted text, URL query
parameters, generated audio, and caption contents from all captured logs.
