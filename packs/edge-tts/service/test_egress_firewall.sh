#!/usr/bin/env bash
set -euo pipefail

service_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
entrypoint="$service_dir/edge-tts-entrypoint.sh"
tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT
mockbin="$tmpdir/bin"
log="$tmpdir/firewall.log"
resolv_conf="$tmpdir/resolv.conf"
hosts_file="$tmpdir/hosts"
mkdir "$mockbin"
: > "$log"
printf 'nameserver 1.1.1.1\n' > "$resolv_conf"
: > "$hosts_file"

cat > "$mockbin/mock-command" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
command_name=${0##*/}
printf '%s' "$command_name" >> "$LOG"
printf ' %s' "$@" >> "$LOG"
printf '\n' >> "$LOG"

case "$command_name" in
  id) [[ "${1:-}" == -u ]] && printf '0\n' ;;
  getent) printf '8.8.8.8 STREAM speech.platform.bing.com\n8.8.8.8 DGRAM speech.platform.bing.com\n' ;;
esac
EOF
chmod 0755 "$mockbin/mock-command"
for command_name in iptables ip6tables getent setpriv chown chmod id; do
  ln -s mock-command "$mockbin/$command_name"
done

assert_contains() {
  grep -Fqx "$1" "$2" || { printf 'missing command: %s\n' "$1" >&2; cat "$2" >&2; exit 1; }
}

assert_not_contains() {
  ! grep -Fq -- "$1" "$2" || { printf 'unexpected command: %s\n' "$1" >&2; cat "$2" >&2; exit 1; }
}

assert_before() {
  local first_line second_line
  first_line=$(grep -Fnx "$1" "$3" | head -n1 | cut -d: -f1)
  second_line=$(grep -Fnx "$2" "$3" | head -n1 | cut -d: -f1)
  [[ -n "$first_line" && -n "$second_line" && "$first_line" -lt "$second_line" ]] || {
    printf 'expected command before setpriv: %s\n' "$1" >&2
    cat "$3" >&2
    exit 1
  }
}

[[ -x "$entrypoint" ]] || { printf 'missing entrypoint: %s\n' "$entrypoint" >&2; exit 1; }
PATH="$mockbin:$PATH" LOG="$log" EDGE_TTS_RESOLV_CONF="$resolv_conf" EDGE_TTS_HOSTS_FILE="$hosts_file" "$entrypoint" /app/synthesize.py

assert_contains 'iptables -N AIHUB_EDGE_TTS_OUTPUT' "$log"
assert_contains 'iptables -A AIHUB_EDGE_TTS_OUTPUT -o lo -j ACCEPT' "$log"
assert_contains 'iptables -A AIHUB_EDGE_TTS_OUTPUT -d 1.1.1.1 -p udp --dport 53 -j ACCEPT' "$log"
assert_contains 'iptables -A AIHUB_EDGE_TTS_OUTPUT -d 1.1.1.1 -p tcp --dport 53 -j ACCEPT' "$log"
assert_contains 'iptables -D AIHUB_EDGE_TTS_OUTPUT -d 1.1.1.1 -p udp --dport 53 -j ACCEPT' "$log"
assert_contains 'iptables -D AIHUB_EDGE_TTS_OUTPUT -d 1.1.1.1 -p tcp --dport 53 -j ACCEPT' "$log"
assert_contains 'iptables -A AIHUB_EDGE_TTS_OUTPUT -d 8.8.8.8 -p tcp --dport 443 -j ACCEPT' "$log"
assert_contains 'iptables -A AIHUB_EDGE_TTS_OUTPUT -j DROP' "$log"
assert_contains 'ip6tables -A AIHUB_EDGE_TTS_OUTPUT6 -j DROP' "$log"
assert_contains 'chown edge:edge /workspace/input/request.json' "$log"
assert_contains 'chmod 0400 /workspace/input/request.json' "$log"
assert_contains 'chown edge:edge /workspace/output' "$log"
assert_contains 'chmod 0700 /workspace/output' "$log"
assert_contains 'setpriv --reuid=edge --regid=edge --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/synthesize.py' "$log"
assert_before 'iptables -A AIHUB_EDGE_TTS_OUTPUT -j DROP' 'setpriv --reuid=edge --regid=edge --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/synthesize.py' "$log"
assert_before 'chmod 0700 /workspace/output' 'setpriv --reuid=edge --regid=edge --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/synthesize.py' "$log"
assert_not_contains '--dport 80' "$log"
! grep -Eq '^(iptables|ip6tables).* -d [^ ]*/' "$log" || { printf 'unexpected CIDR firewall rule\n' >&2; cat "$log" >&2; exit 1; }
grep -Fqx '8.8.8.8 speech.platform.bing.com' "$hosts_file" || { printf 'provider IP was not pinned\n' >&2; exit 1; }

: > "$log"
if PATH="$mockbin:$PATH" LOG="$log" EDGE_TTS_EGRESS_FORCE_FAIL=1 "$entrypoint" /app/synthesize.py > /dev/null 2> "$tmpdir/stderr"; then
  printf 'forced egress failure unexpectedly succeeded\n' >&2
  exit 1
fi
grep -Fqx 'AIHUB_ERROR_CODE=upstream_unavailable' "$tmpdir/stderr" || { cat "$tmpdir/stderr" >&2; exit 1; }
assert_not_contains 'setpriv' "$log"

printf 'test_egress_firewall: ok\n'
