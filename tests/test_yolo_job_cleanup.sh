#!/usr/bin/env bash
set -euo pipefail

jobs=(
  "/DATA/3waAIHub/packs/yolo/jobs/yolo_predict.sh"
  "/DATA/3waAIHub/packs/yolo/jobs/yolo_train.sh"
  "/DATA/3waAIHub/packs/yolo/jobs/yolo_export_onnx.sh"
)

for job in "${jobs[@]}"; do
  grep -Fq 'container_name="aihub-${AIHUB_RUN_ID:-${AIHUB_JOB_KEY:-yolo}-$$}"' "$job"
  grep -Fq 'trap '\''docker rm -f "$container_name" >/dev/null 2>&1 || true'\'' TERM INT EXIT' "$job"
  grep -Fq 'docker_args+=(--name "$container_name")' "$job"
done

runner="/DATA/3waAIHub/bin/aihub-run"
grep -Fq 'pcntl_signal(SIGTERM, $forwardSignal)' "$runner"
grep -Fq '@proc_terminate($process, $signal)' "$runner"
grep -Fq "\$result['error'] = 'terminated';" "$runner"

if php -m | grep -qx pcntl; then
  stamp="signal-$$"
  pack_dir="/DATA/3waAIHub/packs/_tmp_${stamp}"
  job_root="/tmp/3waaihub_${stamp}/jobs"
  workspace="${job_root}/signal/001"
  cleanup() {
    rm -rf "$pack_dir" "/tmp/3waaihub_${stamp}"
  }
  trap cleanup EXIT

  mkdir -p "$pack_dir/jobs" "$workspace"
  cat > "$pack_dir/pack.json" <<JSON
{"id":"tmp-${stamp}","version":"0.0.0","local_jobs":[{"job_key":"signal_sleep","entrypoint":"jobs/sleep.sh"}]}
JSON
  cat > "$pack_dir/jobs/sleep.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
trap 'touch "$AIHUB_WORKSPACE/output/trapped"; exit 42' TERM INT
touch "$AIHUB_WORKSPACE/output/started"
while true; do sleep 1; done
SH
  chmod +x "$pack_dir/jobs/sleep.sh"

  AIHUB_LOCAL_JOB_ROOT="$job_root" php "$runner" signal_sleep --pack "tmp-${stamp}" --workspace "$workspace" >/tmp/3waaihub_${stamp}/stdout.log 2>/tmp/3waaihub_${stamp}/stderr.log &
  runner_pid=$!
  for _ in $(seq 1 50); do
    [[ -f "$workspace/output/started" ]] && break
    sleep 0.1
  done
  [[ -f "$workspace/output/started" ]]

  kill -TERM "$runner_pid"
  set +e
  wait "$runner_pid"
  runner_code=$?
  set -e
  [[ "$runner_code" -ne 0 ]]
  [[ -f "$workspace/output/trapped" ]]
  grep -Fq '"error":"terminated"' "$workspace/result.json"
  grep -Fq '"signal":15' "$workspace/status.json"
fi

echo "yolo job cleanup source OK"
