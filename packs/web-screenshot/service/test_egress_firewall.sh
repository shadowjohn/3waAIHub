#!/usr/bin/env bash
set -euo pipefail

service_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
entrypoint="$service_dir/capture-entrypoint.sh"
tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT
mockbin="$tmpdir/bin"
LOG="$tmpdir/firewall.log"
mkdir "$mockbin"
: > "$LOG"

cat > "$mockbin/mock-command" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
command_name=${0##*/}
printf '%s' "$command_name" >> "$LOG"
printf ' %s' "$@" >> "$LOG"
printf '\n' >> "$LOG"

if [[ "$command_name" == id && "${1:-}" == -u ]]; then
  printf '0\n'
fi
EOF
chmod 0755 "$mockbin/mock-command"

for command_name in iptables ip6tables getent setpriv id; do
  ln -s mock-command "$mockbin/$command_name"
done

assert_contains() {
  local needle=$1
  local file=$2
  grep -Fqx "$needle" "$file" || {
    printf 'missing command: %s\n' "$needle" >&2
    cat "$file" >&2
    exit 1
  }
}

assert_not_contains() {
  local needle=$1
  local file=$2
  if grep -Fq "$needle" "$file"; then
    printf 'unexpected command: %s\n' "$needle" >&2
    cat "$file" >&2
    exit 1
  fi
}

[[ -x "$entrypoint" ]] || {
  printf 'missing entrypoint: %s\n' "$entrypoint" >&2
  exit 1
}
pack_manifest="$service_dir/../pack.json"
if [[ -f "$pack_manifest" ]] && ! grep -Fqx '        "entrypoint": ["/app/capture-entrypoint.sh", "/app/capture"],' "$pack_manifest"; then
  printf 'Pack runner must invoke capture-entrypoint.sh with /app/capture\n' >&2
  exit 1
fi

PATH="$mockbin:$PATH" LOG="$LOG" "$entrypoint" /app/capture

assert_contains 'iptables -N AIHUB_CAPTURE_OUTPUT' "$LOG"
assert_contains 'iptables -A AIHUB_CAPTURE_OUTPUT -d 10.0.0.0/8 -j REJECT' "$LOG"
assert_contains 'iptables -A AIHUB_CAPTURE_OUTPUT -d 172.16.0.0/12 -j REJECT' "$LOG"
assert_contains 'iptables -A AIHUB_CAPTURE_OUTPUT -d 169.254.0.0/16 -j REJECT' "$LOG"
assert_contains 'ip6tables -A AIHUB_CAPTURE_OUTPUT6 -d fc00::/7 -j REJECT' "$LOG"
assert_contains 'ip6tables -A AIHUB_CAPTURE_OUTPUT6 -d fe80::/10 -j REJECT' "$LOG"
assert_contains 'setpriv --reuid=capture --regid=capture --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/capture' "$LOG"

: > "$LOG"
if PATH="$mockbin:$PATH" LOG="$LOG" CAPTURE_EGRESS_FORCE_FAIL=1 "$entrypoint" /app/capture; then
  printf 'forced setup failure unexpectedly succeeded\n' >&2
  exit 1
fi
assert_not_contains 'setpriv' "$LOG"

printf 'test_egress_firewall: ok\n'
