#!/usr/bin/env bash
set -euo pipefail

readonly CHAIN=AIHUB_EDGE_TTS_OUTPUT
readonly CHAIN6=AIHUB_EDGE_TTS_OUTPUT6
readonly PROVIDER_HOST=speech.platform.bing.com
readonly RESOLV_CONF=${EDGE_TTS_RESOLV_CONF:-/etc/resolv.conf}
readonly HOSTS_FILE=${EDGE_TTS_HOSTS_FILE:-/etc/hosts}

fail_upstream() {
  printf 'AIHUB_ERROR_CODE=upstream_unavailable\n' >&2
  exit 1
}

run_rule() {
  "$@" > /dev/null 2>&1 || fail_upstream
}

append_rule() {
  local tool=$1
  local chain=$2
  shift 2
  run_rule "$tool" -A "$chain" "$@"
}

remove_rule() {
  local tool=$1
  local chain=$2
  shift 2
  run_rule "$tool" -D "$chain" "$@"
}

create_chain() {
  local tool=$1
  local chain=$2

  "$tool" -N "$chain" > /dev/null 2>&1 || true
  run_rule "$tool" -F "$chain"
  run_rule "$tool" -I OUTPUT 1 -j "$chain"
  run_rule "$tool" -C OUTPUT -j "$chain"
}

grant_workspace_access() {
  run_rule setfacl -m u:edge:--x /workspace/input
  getfacl -cp /workspace/input 2>/dev/null | grep -Fqx 'user:edge:--x' || fail_upstream
  run_rule setfacl -m u:edge:r-- /workspace/input/request.json
  getfacl -cp /workspace/input/request.json 2>/dev/null | grep -Fqx 'user:edge:r--' || fail_upstream
  run_rule setfacl -m u:edge:rwx /workspace/output
  getfacl -cp /workspace/output 2>/dev/null | grep -Fqx 'user:edge:rwx' || fail_upstream
}

grant_demo_workspace_access() {
  run_rule setfacl -m u:edge:rwx /workspace/output
  getfacl -cp /workspace/output 2>/dev/null | grep -Fqx 'user:edge:rwx' || fail_upstream
}

is_ipv4() {
  python3 - "$1" <<'PY'
import ipaddress
import sys

try:
    address = ipaddress.ip_address(sys.argv[1])
except ValueError:
    raise SystemExit(1)
raise SystemExit(0 if address.version == 4 else 1)
PY
}

is_global_ipv4() {
  python3 - "$1" <<'PY'
import ipaddress
import sys

try:
    address = ipaddress.ip_address(sys.argv[1])
except ValueError:
    raise SystemExit(1)
raise SystemExit(0 if address.version == 4 and address.is_global else 1)
PY
}

[[ "${EDGE_TTS_EGRESS_FORCE_FAIL:-}" != 1 ]] || fail_upstream
[[ "$(id -u)" == 0 && -r "$RESOLV_CONF" && $# -eq 1 ]] || fail_upstream
case "$1" in
  /app/synthesize.py|/app/generate_demos.py) ;;
  *) fail_upstream ;;
esac

declare -a resolvers=()
declare -A resolver_seen=()
while read -r kind value _; do
  if [[ "$kind" == nameserver ]] && is_ipv4 "$value" && [[ -z "${resolver_seen[$value]:-}" ]]; then
    resolvers+=("$value")
    resolver_seen[$value]=1
  fi
done < "$RESOLV_CONF"
(( ${#resolvers[@]} > 0 )) || fail_upstream

create_chain iptables "$CHAIN"
append_rule iptables "$CHAIN" -o lo -j ACCEPT
append_rule iptables "$CHAIN" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
for resolver in "${resolvers[@]}"; do
  append_rule iptables "$CHAIN" -d "$resolver" -p udp --dport 53 -j ACCEPT
  append_rule iptables "$CHAIN" -d "$resolver" -p tcp --dport 53 -j ACCEPT
done
append_rule iptables "$CHAIN" -j DROP

create_chain ip6tables "$CHAIN6"
append_rule ip6tables "$CHAIN6" -o lo -j ACCEPT
append_rule ip6tables "$CHAIN6" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
append_rule ip6tables "$CHAIN6" -j DROP

declare -a provider_ips=()
declare -A provider_seen=()
while read -r address _; do
  if is_global_ipv4 "$address" && [[ -z "${provider_seen[$address]:-}" ]]; then
    provider_ips+=("$address")
    provider_seen[$address]=1
  fi
done < <(getent ahostsv4 "$PROVIDER_HOST" 2>/dev/null || true)
(( ${#provider_ips[@]} > 0 )) || fail_upstream

for address in "${provider_ips[@]}"; do
  if ! (printf '%s %s\n' "$address" "$PROVIDER_HOST" >> "$HOSTS_FILE") 2>/dev/null; then
    fail_upstream
  fi
done

for resolver in "${resolvers[@]}"; do
  remove_rule iptables "$CHAIN" -d "$resolver" -p udp --dport 53 -j ACCEPT
  remove_rule iptables "$CHAIN" -d "$resolver" -p tcp --dport 53 -j ACCEPT
done
remove_rule iptables "$CHAIN" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
remove_rule ip6tables "$CHAIN6" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
remove_rule iptables "$CHAIN" -j DROP
for address in "${provider_ips[@]}"; do
  append_rule iptables "$CHAIN" -d "$address" -p tcp --dport 443 -j ACCEPT
done
append_rule iptables "$CHAIN" -j DROP

case "$1" in
  /app/synthesize.py) grant_workspace_access ;;
  /app/generate_demos.py) grant_demo_workspace_access ;;
esac

exec setpriv --reuid=edge --regid=edge --clear-groups \
  --bounding-set=-all --ambient-caps=-all -- "$@"
