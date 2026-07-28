#!/usr/bin/env bash
set -euo pipefail

network='aihub-capture-egress'
subnet='172.31.240.0/24'
chain='AIHUB_CAPTURE_EGRESS'

network_ready() {
    [ "$(docker network inspect -f '{{.Driver}}|{{len .IPAM.Config}}|{{(index .IPAM.Config 0).Subnet}}|{{.EnableIPv6}}' "$network" 2>/dev/null)" = "bridge|1|$subnet|false" ]
}

jump_ready() {
    local first_rule
    first_rule=$(iptables -S DOCKER-USER 2>/dev/null | awk '$1 == "-A" { print; exit }') || return 1
    [ "$first_rule" = "-A DOCKER-USER -s $subnet -j $chain" ]
}

case "${1:-}" in
--check)
    if network_ready && jump_ready; then
        printf 'capture_egress=ready\n'
        exit 0
    fi
    exit 1
    ;;
'')
    ;;
*)
    printf 'Usage: %s [--check]\n' "${0##*/}" >&2
    exit 2
    ;;
esac

if [ "$(id -u)" -ne 0 ]; then
    printf 'ERROR: root required to install capture egress network.\n' >&2
    exit 1
fi

if ! docker network inspect "$network" >/dev/null 2>&1; then
    docker network create --subnet "$subnet" --ipv6=false "$network" >/dev/null
fi
network_ready || exit 1

iptables -L "$chain" -n >/dev/null 2>&1 || iptables -N "$chain"
for destination in 0.0.0.0/8 10/8 100.64/10 127/8 169.254/16 172.16/12 192.0.0/24 192.168/16 198.18/15 224/4 240/4; do
    iptables -C "$chain" -d "$destination" -j REJECT >/dev/null 2>&1 || iptables -A "$chain" -d "$destination" -j REJECT
done
iptables -C "$chain" -p tcp -m multiport ! --dports 80,443 -j REJECT >/dev/null 2>&1 || iptables -A "$chain" -p tcp -m multiport ! --dports 80,443 -j REJECT
iptables -C "$chain" -j RETURN >/dev/null 2>&1 || iptables -A "$chain" -j RETURN
jump_ready || iptables -I DOCKER-USER 1 -s "$subnet" -j "$chain"
