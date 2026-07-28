#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
script="$root/scripts/install_capture_egress_network.sh"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

assert_contains() {
    local needle=$1 haystack=$2
    [[ $haystack == *"$needle"* ]] || fail "missing: $needle"
}

assert_not_contains() {
    local needle=$1 haystack=$2
    [[ $haystack != *"$needle"* ]] || fail "unexpected: $needle"
}

[ -x "$script" ] || fail "capture egress installer must be executable"

mock_bin="$tmp/bin"
mkdir -p "$mock_bin"

cat > "$mock_bin/id" <<'EOF'
#!/usr/bin/env bash
if [ "${1:-}" = '-u' ]; then
    printf '0\n'
    exit 0
fi
exec /usr/bin/id "$@"
EOF

cat > "$mock_bin/docker" <<'EOF'
#!/usr/bin/env bash
set -eu
{ printf 'docker'; printf ' %s' "$@"; printf '\n'; } >> "$MOCK_LOG"
case "${1:-}:${2:-}" in
network:inspect)
    if [ "${MOCK_NETWORK_EXISTS:-0}" = 1 ] || [ -f "$MOCK_STATE/network-created" ]; then
        printf '%s\n' "${MOCK_NETWORK_STATE:-172.31.240.0/24|false}"
        exit 0
    fi
    exit 1
    ;;
network:create)
    : > "$MOCK_STATE/network-created"
    ;;
esac
EOF

cat > "$mock_bin/iptables" <<'EOF'
#!/usr/bin/env bash
set -eu
{ printf 'iptables'; printf ' %s' "$@"; printf '\n'; } >> "$MOCK_LOG"
case "${1:-}" in
-L) exit "${MOCK_CHAIN_EXISTS:-0}" ;;
-C) exit "${MOCK_IPTABLES_CHECK_EXIT:-0}" ;;
-N|-A) exit 0 ;;
esac
EOF
chmod 0755 "$mock_bin/id" "$mock_bin/docker" "$mock_bin/iptables"

run() {
    local log=$1
    shift
    : > "$log"
    env PATH="$mock_bin:$PATH" MOCK_LOG="$log" MOCK_STATE="$tmp/state" "$@"
}

mkdir -p "$tmp/state"

log="$tmp/check-ready.log"
ready=$(run "$log" MOCK_NETWORK_EXISTS=1 MOCK_IPTABLES_CHECK_EXIT=0 "$script" --check)
[ "$ready" = 'capture_egress=ready' ] || fail '--check must print only the ready status'
check_log=$(<"$log")
assert_contains 'docker network inspect -f {{(index .IPAM.Config 0).Subnet}}|{{.EnableIPv6}} aihub-capture-egress' "$check_log"
assert_contains 'iptables -C DOCKER-USER -s 172.31.240.0/24 -j AIHUB_CAPTURE_EGRESS' "$check_log"
assert_not_contains 'network create' "$check_log"
assert_not_contains 'iptables -N' "$check_log"
assert_not_contains 'iptables -A' "$check_log"

log="$tmp/check-missing-jump.log"
if run "$log" MOCK_NETWORK_EXISTS=1 MOCK_IPTABLES_CHECK_EXIT=1 "$script" --check > "$tmp/check-missing-jump.out"; then
    fail '--check must fail when the DOCKER-USER jump is missing'
fi
[ ! -s "$tmp/check-missing-jump.out" ] || fail '--check failure must not report ready'
missing_jump_log=$(<"$log")
assert_not_contains 'network create' "$missing_jump_log"
assert_not_contains 'iptables -N' "$missing_jump_log"
assert_not_contains 'iptables -A' "$missing_jump_log"

log="$tmp/check-wrong-subnet.log"
if run "$log" MOCK_NETWORK_EXISTS=1 MOCK_NETWORK_STATE='172.31.241.0/24|false' MOCK_IPTABLES_CHECK_EXIT=0 "$script" --check > "$tmp/check-wrong-subnet.out"; then
    fail '--check must fail when the Docker subnet differs'
fi
[ ! -s "$tmp/check-wrong-subnet.out" ] || fail '--check must not report ready for a wrong subnet'
wrong_subnet_log=$(<"$log")
assert_not_contains 'network create' "$wrong_subnet_log"
assert_not_contains 'iptables -N' "$wrong_subnet_log"
assert_not_contains 'iptables -A' "$wrong_subnet_log"

log="$tmp/check-ipv6.log"
if run "$log" MOCK_NETWORK_EXISTS=1 MOCK_NETWORK_STATE='172.31.240.0/24|true' MOCK_IPTABLES_CHECK_EXIT=0 "$script" --check > "$tmp/check-ipv6.out"; then
    fail '--check must fail when Docker IPv6 is enabled'
fi
[ ! -s "$tmp/check-ipv6.out" ] || fail '--check must not report ready when IPv6 is enabled'
ipv6_log=$(<"$log")
assert_not_contains 'network create' "$ipv6_log"
assert_not_contains 'iptables -N' "$ipv6_log"
assert_not_contains 'iptables -A' "$ipv6_log"

log="$tmp/install-network.log"
run "$log" MOCK_NETWORK_EXISTS=0 MOCK_CHAIN_EXISTS=1 MOCK_IPTABLES_CHECK_EXIT=0 "$script"
network_log=$(<"$log")
assert_contains 'docker network create --subnet 172.31.240.0/24 --ipv6=false aihub-capture-egress' "$network_log"

log="$tmp/install-rules.log"
run "$log" MOCK_NETWORK_EXISTS=1 MOCK_CHAIN_EXISTS=1 MOCK_IPTABLES_CHECK_EXIT=1 "$script"
rules_log=$(<"$log")
for destination in 0.0.0.0/8 10/8 100.64/10 127/8 169.254/16 172.16/12 192.0.0/24 192.168/16 198.18/15 224/4 240/4; do
    assert_contains "iptables -A AIHUB_CAPTURE_EGRESS -d $destination -j REJECT" "$rules_log"
done
assert_contains 'iptables -A AIHUB_CAPTURE_EGRESS -p tcp -m multiport ! --dports 80,443 -j REJECT' "$rules_log"
assert_contains 'iptables -A AIHUB_CAPTURE_EGRESS -j RETURN' "$rules_log"
assert_contains 'iptables -A DOCKER-USER -s 172.31.240.0/24 -j AIHUB_CAPTURE_EGRESS' "$rules_log"

log="$tmp/install-idempotent.log"
run "$log" MOCK_NETWORK_EXISTS=1 MOCK_CHAIN_EXISTS=0 MOCK_IPTABLES_CHECK_EXIT=0 "$script"
idempotent_log=$(<"$log")
assert_not_contains 'network create' "$idempotent_log"
assert_not_contains 'iptables -N' "$idempotent_log"
assert_not_contains 'iptables -A' "$idempotent_log"

unit=$(<"$root/deploy/systemd/aihub-capture-egress.service")
assert_contains 'After=docker.service' "$unit"
assert_contains 'PartOf=docker.service' "$unit"
assert_contains 'Before=cron.service' "$unit"

printf 'ok - capture egress installer checks and guarded commands\n'
