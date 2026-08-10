#!/usr/bin/env bash
set -euo pipefail

SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SANDBOX="$(mktemp -d "${TMPDIR:-/tmp}/3waaihub_linux_release_install.XXXXXX")"
HOST_MODELS_GUARD="$SOURCE_ROOT/tests/fixtures/linux-install"

cleanup() {
  rm -rf -- "$SANDBOX"
}
trap cleanup EXIT

(
  cd "$SOURCE_ROOT"
  tar \
    --exclude='./.git' \
    --exclude='./data' \
    --exclude='./dist' \
    -cf - .
) | tar -xf - -C "$SANDBOX"

cd "$SANDBOX"

install_output="$(
  BASH_ENV="$HOST_MODELS_GUARD/bash_env.sh" \
  CRON_FILE="$SANDBOX/3waaihub-command-worker.cron" \
  ./install.sh 2>&1
)"
printf '%s\n' "$install_output"

test -f dist/release-manifest.json
test -d dist/public
test -d data
test ! -e dist/data
php scripts/build_release.php --check

check_output="$(./install.sh --check 2>&1)"
printf '%s\n' "$check_output"
grep -Fq 'Release artifact: VERIFIED' <<<"$check_output"
grep -Fq "Web document root: $SANDBOX/dist/public" <<<"$check_output"
grep -Fq "Command worker cron: sudo $SANDBOX/dist/scripts/install_command_worker_cron.sh" <<<"$install_output"
grep -Fq "Command worker loop: $SANDBOX/dist/crontab/1min.sh" <<<"$install_output"

echo 'PASS test_linux_release_install'
