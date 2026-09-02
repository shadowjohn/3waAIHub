#!/usr/bin/env bash

set -euo pipefail

ROOT="${AIHUB_TEST_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/aihub-yolo-docker-probe.XXXXXX")"
TEST_DATA_DIR="$(dirname "$TEST_ROOT")/3waaihub_test_data_$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"

cleanup() {
    rm -rf "$TEST_ROOT"
    rm -rf "$TEST_DATA_DIR"
}
trap cleanup EXIT

fail() {
    printf 'test_yolo_docker_probe: %s\n' "$*" >&2
    exit 1
}

mkdir -p "$TEST_ROOT/bin" "$TEST_DATA_DIR"
cat > "$TEST_ROOT/bin/docker" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" != "image" || "${2:-}" != "inspect" ]]; then
    printf 'unexpected fake docker command: %s\n' "$*" >&2
    exit 64
fi

case "${AIHUB_TEST_DOCKER_PROBE_MODE:-permission}" in
    permission)
        printf 'permission denied while trying to connect to the Docker daemon socket at unix:///var/run/docker.sock\n' >&2
        exit 1
        ;;
    unavailable)
        printf 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?\n' >&2
        exit 1
        ;;
    missing)
        printf 'Error response from daemon: No such image: 3waaihub-yolo-main:0.1.0\n' >&2
        exit 1
        ;;
    *)
        printf 'unknown probe mode: %s\n' "${AIHUB_TEST_DOCKER_PROBE_MODE}" >&2
        exit 64
        ;;
esac
EOF
chmod 700 "$TEST_ROOT/bin/docker"

AIHUB_TEST_DB="$TEST_ROOT/aihub.sqlite" \
AIHUB_TEST_DATA_DIR="$TEST_DATA_DIR" \
php "$ROOT/scripts/init_db.php" >/dev/null

prepare_workspace() {
    local job_key="$1"
    local workspace="$2"

    mkdir -p "$workspace/input"
    case "$job_key" in
        yolo_train)
            mkdir -p "$workspace/datasets"
            printf 'path: datasets\ntrain: images\nval: images\nnames: [animal]\n' > "$workspace/data.yaml"
            printf '{"epochs":1,"imgsz":640}' > "$workspace/train_config.json"
            ;;
        yolo_predict)
            printf 'fake image' > "$workspace/input/sample.jpg"
            printf '{"image":"input/sample.jpg"}' > "$workspace/request.json"
            ;;
        yolo_export_onnx)
            printf 'fake model' > "$workspace/input/best.pt"
            printf '{"model":"input/best.pt","format":"onnx"}' > "$workspace/request.json"
            ;;
        *)
            fail "unknown job: $job_key"
            ;;
    esac
}

run_case() {
    local job_key="$1"
    local probe_mode="$2"
    local expected_error="$3"
    local expected_stderr="$4"
    local workspace="$TEST_ROOT/jobs/yolo/${job_key}-${probe_mode}"
    local exit_code

    prepare_workspace "$job_key" "$workspace"

    set +e
    PATH="$TEST_ROOT/bin:$PATH" \
    AIHUB_TEST_DB="$TEST_ROOT/aihub.sqlite" \
    AIHUB_TEST_DATA_DIR="$TEST_DATA_DIR" \
    AIHUB_TEST_DOCKER_PROBE_MODE="$probe_mode" \
    AIHUB_LOCAL_JOB_ROOT="$TEST_ROOT/jobs" \
    php "$ROOT/bin/aihub-run" "$job_key" --pack yolo --workspace "$workspace" --run-id "probe-${job_key}-${probe_mode}" \
        >"$workspace/runner.stdout" 2>"$workspace/runner.stderr"
    exit_code=$?
    set -e

    [[ "$exit_code" -ne 0 ]] || fail "$job_key/$probe_mode should fail before starting Docker"

    python3 - "$workspace/result.json" "$expected_error" "$expected_stderr" <<'PY'
import json
import sys

result_path, expected_error, expected_stderr = sys.argv[1:]
with open(result_path, encoding="utf-8") as handle:
    payload = json.load(handle)

actual_error = payload.get("error")
if actual_error != expected_error:
    raise SystemExit(f"expected error {expected_error!r}, got {actual_error!r}: {payload!r}")

stderr = payload.get("stderr", "")
if expected_stderr.lower() not in stderr.lower():
    raise SystemExit(f"job error did not retain Docker stderr: {payload!r}")
PY

    grep -Fqi "$expected_stderr" "$workspace/logs/stderr.log" \
        || fail "$job_key/$probe_mode did not retain Docker stderr in the job log"
}

# 模擬非 docker 群組的排程帳號；三支 Local Job 必須都保留正確錯誤。
run_case yolo_train permission docker_permission_denied 'permission denied'
run_case yolo_predict permission docker_permission_denied 'permission denied'
run_case yolo_export_onnx permission docker_permission_denied 'permission denied'

# 共用 probe 也必須將 daemon 不可用與 image 不存在分開。
run_case yolo_predict unavailable docker_unavailable 'cannot connect to the docker daemon'
run_case yolo_predict missing yolo_image_missing 'no such image'

for job in yolo_train yolo_predict yolo_export_onnx; do
    grep -Fq 'yolo_docker_probe_image "$image"' "$ROOT/packs/yolo/jobs/${job}.sh" \
        || fail "$job did not use the shared Docker probe"
done

printf 'test_yolo_docker_probe: ok\n'
