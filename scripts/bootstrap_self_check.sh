#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

before=""
if [ -f data/3waaihub.sqlite ]; then
  before="$(stat -c %Y data/3waaihub.sqlite)"
fi

check_output="$(./install.sh --check)"
printf '%s\n' "$check_output" | grep -q "Mode: check" || fail "--check did not print check mode"
printf '%s\n' "$check_output" | grep -q "PHP:" || fail "--check did not print PHP status"
printf '%s\n' "$check_output" | grep -q "Docker:" || fail "--check did not print Docker status"

after=""
if [ -f data/3waaihub.sqlite ]; then
  after="$(stat -c %Y data/3waaihub.sqlite)"
fi
[ "$before" = "$after" ] || fail "--check modified SQLite"

if [ "$(id -u)" != "0" ]; then
  set +e
  root_output="$(./install.sh --bootstrap-host --with-docker 2>&1)"
  root_code=$?
  set -e
  [ "$root_code" -ne 0 ] || fail "--bootstrap-host succeeded without root"
  printf '%s\n' "$root_output" | grep -q "ERROR: --bootstrap-host requires root." || fail "missing root error"

  set +e
  nvidia_root_output="$(./install.sh --bootstrap-host --with-nvidia 2>&1)"
  nvidia_root_code=$?
  set -e
  [ "$nvidia_root_code" -ne 0 ] || fail "--bootstrap-host --with-nvidia succeeded without root"
  printf '%s\n' "$nvidia_root_output" | grep -q "ERROR: --bootstrap-host requires root." || fail "missing nvidia root error"

  set +e
  docker_output="$(./scripts/install_docker_ubuntu.sh 2>&1)"
  docker_code=$?
  set -e
  [ "$docker_code" -ne 0 ] || fail "Docker installer succeeded without root"
  printf '%s\n' "$docker_output" | grep -q "ERROR: root required." || fail "Docker installer missing root guard"

  set +e
  nvidia_output="$(./scripts/install_nvidia_container_toolkit.sh 2>&1)"
  nvidia_code=$?
  set -e
  [ "$nvidia_code" -ne 0 ] || fail "NVIDIA installer succeeded without root"
  printf '%s\n' "$nvidia_output" | grep -q "ERROR: root required." || fail "NVIDIA installer missing root guard"
fi

echo "bootstrap_self_check ok"
