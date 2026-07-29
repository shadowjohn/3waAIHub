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
  getent)
    provider_ips=${EDGE_TTS_TEST_PROVIDER_IPS-8.8.8.8}
    for address in $provider_ips; do
      printf '%s STREAM speech.platform.bing.com\n' "$address"
    done
    ;;
  getfacl)
    if [[ "${EDGE_TTS_ACL_INVALID:-}" != 1 ]]; then
      case "${!#}" in
        /workspace/input) printf 'user:edge:--x\n' ;;
        /workspace/input/request.json) printf 'user:edge:r--\n' ;;
        /workspace/output) printf 'user:edge:rwx\n' ;;
      esac
    fi
    ;;
esac
EOF
chmod 0755 "$mockbin/mock-command"
for command_name in iptables ip6tables getent setpriv setfacl getfacl id; do
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

assert_last_before() {
  local first_line second_line
  first_line=$(grep -Fnx "$1" "$3" | head -n1 | cut -d: -f1)
  second_line=$(grep -Fnx "$2" "$3" | tail -n1 | cut -d: -f1)
  [[ -n "$first_line" && -n "$second_line" && "$first_line" -lt "$second_line" ]] || {
    printf 'expected command before final rule: %s\n' "$1" >&2
    cat "$3" >&2
    exit 1
  }
}

expect_upstream_failure() {
  local stderr="$tmpdir/$1.stderr"
  shift
  : > "$log"
  if env PATH="$mockbin:$PATH" LOG="$log" EDGE_TTS_RESOLV_CONF="$resolv_conf" EDGE_TTS_HOSTS_FILE="$hosts_file" "$@" "$entrypoint" /app/synthesize.py > /dev/null 2> "$stderr"; then
    printf 'expected egress setup failure unexpectedly succeeded\n' >&2
    exit 1
  fi
  grep -Fqx 'AIHUB_ERROR_CODE=upstream_unavailable' "$stderr" || { cat "$stderr" >&2; exit 1; }
  assert_not_contains 'setpriv' "$log"
}

[[ -x "$entrypoint" ]] || { printf 'missing entrypoint: %s\n' "$entrypoint" >&2; exit 1; }
PATH="$mockbin:$PATH" LOG="$log" EDGE_TTS_RESOLV_CONF="$resolv_conf" EDGE_TTS_HOSTS_FILE="$hosts_file" "$entrypoint" /app/synthesize.py

assert_contains 'iptables -N AIHUB_EDGE_TTS_OUTPUT' "$log"
assert_contains 'iptables -A AIHUB_EDGE_TTS_OUTPUT -o lo -j ACCEPT' "$log"
assert_contains 'iptables -A AIHUB_EDGE_TTS_OUTPUT -d 1.1.1.1 -p udp --dport 53 -j ACCEPT' "$log"
assert_contains 'iptables -A AIHUB_EDGE_TTS_OUTPUT -d 1.1.1.1 -p tcp --dport 53 -j ACCEPT' "$log"
assert_contains 'iptables -D AIHUB_EDGE_TTS_OUTPUT -d 1.1.1.1 -p udp --dport 53 -j ACCEPT' "$log"
assert_contains 'iptables -D AIHUB_EDGE_TTS_OUTPUT -d 1.1.1.1 -p tcp --dport 53 -j ACCEPT' "$log"
assert_contains 'iptables -D AIHUB_EDGE_TTS_OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT' "$log"
assert_contains 'ip6tables -D AIHUB_EDGE_TTS_OUTPUT6 -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT' "$log"
assert_contains 'iptables -A AIHUB_EDGE_TTS_OUTPUT -d 8.8.8.8 -p tcp --dport 443 -j ACCEPT' "$log"
assert_contains 'iptables -A AIHUB_EDGE_TTS_OUTPUT -j DROP' "$log"
assert_contains 'ip6tables -A AIHUB_EDGE_TTS_OUTPUT6 -j DROP' "$log"
assert_contains 'setfacl -m u:edge:--x /workspace/input' "$log"
assert_contains 'getfacl -cp /workspace/input' "$log"
assert_contains 'setfacl -m u:edge:r-- /workspace/input/request.json' "$log"
assert_contains 'getfacl -cp /workspace/input/request.json' "$log"
assert_contains 'setfacl -m u:edge:rwx /workspace/output' "$log"
assert_contains 'getfacl -cp /workspace/output' "$log"
assert_contains 'setpriv --reuid=edge --regid=edge --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/synthesize.py' "$log"
assert_before 'iptables -D AIHUB_EDGE_TTS_OUTPUT -d 1.1.1.1 -p tcp --dport 53 -j ACCEPT' 'iptables -D AIHUB_EDGE_TTS_OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT' "$log"
assert_last_before 'iptables -A AIHUB_EDGE_TTS_OUTPUT -d 8.8.8.8 -p tcp --dport 443 -j ACCEPT' 'iptables -A AIHUB_EDGE_TTS_OUTPUT -j DROP' "$log"
assert_last_before 'iptables -A AIHUB_EDGE_TTS_OUTPUT -j DROP' 'setpriv --reuid=edge --regid=edge --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/synthesize.py' "$log"
assert_before 'setfacl -m u:edge:rwx /workspace/output' 'setpriv --reuid=edge --regid=edge --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/synthesize.py' "$log"
assert_not_contains '--dport 80' "$log"
assert_not_contains 'chown' "$log"
assert_not_contains 'chmod' "$log"
! grep -Eq '^(iptables|ip6tables).* -d [^ ]*/' "$log" || { printf 'unexpected CIDR firewall rule\n' >&2; cat "$log" >&2; exit 1; }
provider_rule='iptables -A AIHUB_EDGE_TTS_OUTPUT -d 8.8.8.8 -p tcp --dport 443 -j ACCEPT'
[[ "$(grep -Fxc "$provider_rule" "$log")" == 1 ]] || { printf 'provider TCP 443 rule must be unique\n' >&2; cat "$log" >&2; exit 1; }
tcp_443_accepts=$(grep -F 'iptables -A AIHUB_EDGE_TTS_OUTPUT ' "$log" | grep -F -- '-p tcp' | grep -F -- '--dport 443' | grep -F -- '-j ACCEPT' || true)
[[ "$tcp_443_accepts" == "$provider_rule" ]] || {
  printf 'TCP 443 ACCEPT must use only the pinned provider destination\n' >&2
  cat "$log" >&2
  exit 1
}
grep -Fqx '8.8.8.8 speech.platform.bing.com' "$hosts_file" || { printf 'provider IP was not pinned\n' >&2; exit 1; }

: > "$log"
PATH="$mockbin:$PATH" LOG="$log" EDGE_TTS_RESOLV_CONF="$resolv_conf" EDGE_TTS_HOSTS_FILE="$hosts_file" "$entrypoint" /app/generate_demos.py
assert_contains 'setfacl -m u:edge:rwx /workspace/output' "$log"
assert_contains 'getfacl -cp /workspace/output' "$log"
assert_contains 'setpriv --reuid=edge --regid=edge --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/generate_demos.py' "$log"
assert_not_contains '/workspace/input' "$log"
assert_not_contains '/workspace/input/request.json' "$log"

: > "$log"
untrusted_stderr="$tmpdir/untrusted.stderr"
if PATH="$mockbin:$PATH" LOG="$log" EDGE_TTS_RESOLV_CONF="$resolv_conf" EDGE_TTS_HOSTS_FILE="$hosts_file" "$entrypoint" /app/untrusted.py > /dev/null 2> "$untrusted_stderr"; then
  printf 'untrusted entrypoint command unexpectedly succeeded\n' >&2
  exit 1
fi
grep -Fqx 'AIHUB_ERROR_CODE=upstream_unavailable' "$untrusted_stderr" || { cat "$untrusted_stderr" >&2; exit 1; }
assert_not_contains 'setpriv' "$log"

empty_resolv="$tmpdir/empty-resolv.conf"
: > "$empty_resolv"
expect_upstream_failure empty_dns "EDGE_TTS_RESOLV_CONF=$empty_resolv"
expect_upstream_failure empty_provider 'EDGE_TTS_TEST_PROVIDER_IPS='
expect_upstream_failure private_or_reserved "EDGE_TTS_TEST_PROVIDER_IPS=127.0.0.1 192.0.2.1"
expect_upstream_failure wildcard_or_cidr "EDGE_TTS_TEST_PROVIDER_IPS=0.0.0.0 0.0.0.0/0"
expect_upstream_failure acl_invalid 'EDGE_TTS_ACL_INVALID=1'
expect_upstream_failure forced 'EDGE_TTS_EGRESS_FORCE_FAIL=1'

printf 'test_egress_firewall: ok\n'
