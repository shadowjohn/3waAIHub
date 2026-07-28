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
if [[ "$command_name" == getfacl && "${CAPTURE_ACL_INVALID:-}" != 1 ]]; then
  case "${!#}" in
    /workspace/input/request.json) printf 'user:capture:r--\n' ;;
    /workspace/output) printf 'user:capture:rwx\n' ;;
  esac
fi
EOF
chmod 0755 "$mockbin/mock-command"

for command_name in iptables ip6tables getent setpriv setfacl getfacl id; do
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

assert_before() {
  local first=$1
  local second=$2
  local file=$3
  local first_line
  local second_line
  first_line=$(grep -Fnx "$first" "$file" | head -n1 | cut -d: -f1)
  second_line=$(grep -Fnx "$second" "$file" | head -n1 | cut -d: -f1)
  [[ -n "$first_line" && -n "$second_line" && "$first_line" -lt "$second_line" ]] || {
    printf 'expected command before setpriv: %s\n' "$first" >&2
    cat "$file" >&2
    exit 1
  }
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
assert_contains 'setfacl -m u:capture:r-- /workspace/input/request.json' "$LOG"
assert_contains 'getfacl -cp /workspace/input/request.json' "$LOG"
assert_contains 'setfacl -m u:capture:rwx /workspace/output' "$LOG"
assert_contains 'getfacl -cp /workspace/output' "$LOG"
assert_contains 'setpriv --reuid=capture --regid=capture --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/capture' "$LOG"
assert_before 'setfacl -m u:capture:r-- /workspace/input/request.json' 'setpriv --reuid=capture --regid=capture --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/capture' "$LOG"
assert_before 'setfacl -m u:capture:rwx /workspace/output' 'setpriv --reuid=capture --regid=capture --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/capture' "$LOG"

: > "$LOG"
if PATH="$mockbin:$PATH" LOG="$LOG" CAPTURE_EGRESS_FORCE_FAIL=1 "$entrypoint" /app/capture; then
  printf 'forced setup failure unexpectedly succeeded\n' >&2
  exit 1
fi
assert_not_contains 'setpriv' "$LOG"

: > "$LOG"
if PATH="$mockbin:$PATH" LOG="$LOG" CAPTURE_ACL_INVALID=1 "$entrypoint" /app/capture; then
  printf 'invalid ACL verification unexpectedly succeeded\n' >&2
  exit 1
fi
assert_not_contains 'setpriv' "$LOG"

printf 'test_egress_firewall: ok\n'
