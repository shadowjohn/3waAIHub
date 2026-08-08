#!/usr/bin/env bash
set -euo pipefail

if [[ "${CRAWLER_EGRESS_FORCE_FAIL:-}" == 1 ]]; then
  printf 'crawler egress setup forced to fail\n' >&2
  exit 1
fi

if [[ "$(id -u)" != 0 ]]; then
  printf 'crawler egress setup requires root\n' >&2
  exit 1
fi

create_chain() {
  local tool=$1
  local chain=$2

  "$tool" -N "$chain" 2>/dev/null || true
  "$tool" -F "$chain"
  "$tool" -I OUTPUT 1 -j "$chain"
  "$tool" -C OUTPUT -j "$chain"
}

append_rule() {
  local tool=$1
  local chain=$2
  shift 2

  "$tool" -A "$chain" "$@"
  "$tool" -C "$chain" "$@"
}

grant_crawler_workspace_access() {
  setfacl -m u:crawler:--x /workspace/input
  getfacl -cp /workspace/input | grep -Fqx 'user:crawler:--x'
  setfacl -m u:crawler:r-- /workspace/input/request.json
  getfacl -cp /workspace/input/request.json | grep -Fqx 'user:crawler:r--'
  setfacl -m u:crawler:rwx /workspace/output
  getfacl -cp /workspace/output | grep -Fqx 'user:crawler:rwx'
}

add_resolver_rules() {
  local resolver
  for resolver in "$@"; do
    getent ahosts "$resolver" >/dev/null
    if [[ "$resolver" == *:* ]]; then
      [[ "$resolver" =~ ^[[:xdigit:]:]+$ ]] || {
        printf 'invalid IPv6 resolver: %s\n' "$resolver" >&2
        exit 1
      }
      append_rule ip6tables AIHUB_CRAWLER_OUTPUT6 -d "$resolver" -p udp --dport 53 -j ACCEPT
      append_rule ip6tables AIHUB_CRAWLER_OUTPUT6 -d "$resolver" -p tcp --dport 53 -j ACCEPT
    elif [[ "$resolver" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
      append_rule iptables AIHUB_CRAWLER_OUTPUT -d "$resolver" -p udp --dport 53 -j ACCEPT
      append_rule iptables AIHUB_CRAWLER_OUTPUT -d "$resolver" -p tcp --dport 53 -j ACCEPT
    else
      printf 'resolver is not numeric: %s\n' "$resolver" >&2
      exit 1
    fi
  done
}

mapfile -t resolvers < <(awk '/^nameserver / { print $2 }' /etc/resolv.conf)
if (( ${#resolvers[@]} == 0 )); then
  printf 'no resolvers configured\n' >&2
  exit 1
fi

create_chain iptables AIHUB_CRAWLER_OUTPUT
create_chain ip6tables AIHUB_CRAWLER_OUTPUT6

append_rule iptables AIHUB_CRAWLER_OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
append_rule ip6tables AIHUB_CRAWLER_OUTPUT6 -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
add_resolver_rules "${resolvers[@]}"

ipv4_blocked=(
  0.0.0.0/8 10.0.0.0/8 100.64.0.0/10 127.0.0.0/8
  169.254.0.0/16 172.16.0.0/12 192.0.0.0/24 192.0.2.0/24
  192.168.0.0/16 198.18.0.0/15 198.51.100.0/24 203.0.113.0/24
  224.0.0.0/4 240.0.0.0/4
)
for range in "${ipv4_blocked[@]}"; do
  append_rule iptables AIHUB_CRAWLER_OUTPUT -d "$range" -j REJECT
done
append_rule iptables AIHUB_CRAWLER_OUTPUT -p tcp -m multiport --dports 80,443 -j ACCEPT
append_rule iptables AIHUB_CRAWLER_OUTPUT -j REJECT

ipv6_blocked=(
  ::/96 ::ffff:0:0/96 64:ff9b::/96 64:ff9b:1::/48 2001::/23
  2001:db8::/32 2002::/16 3fff::/20 fc00::/7 fe80::/10 ff00::/8
)
for range in "${ipv6_blocked[@]}"; do
  append_rule ip6tables AIHUB_CRAWLER_OUTPUT6 -d "$range" -j REJECT
done
append_rule ip6tables AIHUB_CRAWLER_OUTPUT6 -d 2000::/3 -p tcp -m multiport --dports 80,443 -j ACCEPT
append_rule ip6tables AIHUB_CRAWLER_OUTPUT6 -j REJECT

grant_crawler_workspace_access

exec setpriv --reuid=crawler --regid=crawler --clear-groups \
  --bounding-set=-all --ambient-caps=-all -- "$@"
