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
docker image inspect --format '{{.Id}}' 3waaihub/edge-tts:0.1.0
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
  --data '{"text":"\u9019\u662f\u4e00\u6bb5\u975e\u6a5f\u5bc6\u7684\u4e2d\u6587\u5408\u6210\u3002","voice":"zh-TW-HsiaoChenNeural"}' \
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

Read the result only after `success`, select the owned `generated_audio`
artifact, and compare its downloaded SHA-256 with the task result. The result
also contains the synthesis metadata artifact; it is not a subtitle artifact.

```bash
curl --fail --silent --show-error \
  -H "Authorization: Bearer $AIHUB_EDGE_TTS_TOKEN" \
  --output "$WORKDIR/result.json" \
  "$AIHUB_EDGE_TTS_BASE_URL?mode=task_result&task_id=$TASK_ID"

read -r AUDIO_ID AUDIO_SHA256 <<EOF
$(php -r '
  $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  foreach (($value["result"]["artifacts"] ?? []) as $artifact) {
    if (($artifact["type"] ?? null) === "generated_audio") {
      $id = $artifact["id"] ?? null;
      $sha256 = $artifact["sha256"] ?? null;
      if (is_int($id) && preg_match("/^[a-f0-9]{64}$/", (string) $sha256) === 1) {
        echo $id, " ", $sha256;
        exit(0);
      }
    }
  }
  throw new RuntimeException("generated_audio artifact missing");
' "$WORKDIR/result.json")
EOF

MP3="$WORKDIR/generated_audio.mp3"
curl --fail --silent --show-error \
  -H "Authorization: Bearer $AIHUB_EDGE_TTS_TOKEN" \
  --output "$MP3" \
  "$AIHUB_EDGE_TTS_BASE_URL?mode=artifact&artifact_id=$AUDIO_ID"
test "$(sha256sum "$MP3" | awk '{print $1}')" = "$AUDIO_SHA256"
test "$(ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of default=nokey=1:noprint_wrappers=1 "$MP3")" = mp3
DURATION="$(ffprobe -v error -show_entries format=duration -of default=nokey=1:noprint_wrappers=1 "$MP3")"
php -r 'exit(is_numeric($argv[1]) && (float) $argv[1] > 0.0 ? 0 : 1);' "$DURATION"

curl --fail --silent --show-error --request POST \
  -H "Authorization: Bearer $AIHUB_EDGE_TTS_TOKEN" \
  -F "task_id=$TASK_ID" \
  -F "artifact_id=$AUDIO_ID" \
  --output "$WORKDIR/ack.json" \
  "$AIHUB_EDGE_TTS_BASE_URL?mode=task_artifacts_ack"
php -r '
  $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (($value["ok"] ?? false) !== true || empty($value["acknowledged_at"])) {
    throw new RuntimeException("artifact acknowledgement failed");
  }
' "$WORKDIR/ack.json"
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

Record only the task ID, terminal status, artifact ID, SHA-256 match, MP3
codec/duration result, acknowledgement result, and no-GPU-lease result. Redact
the token, submitted text, URL query parameters, and generated audio from all
captured logs.
