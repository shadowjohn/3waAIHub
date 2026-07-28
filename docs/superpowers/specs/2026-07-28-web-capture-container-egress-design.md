# Web Screenshot Container Egress Design

## Decision

Web Screenshot uses a container-local, fail-closed egress firewall. The
Docker runner grants `NET_ADMIN` only to the immutable Web Screenshot capture
container. Its trusted entrypoint installs and verifies the rules in that
container's own network namespace, removes all capabilities, then starts the
runtime as a dedicated non-root user.

No host firewall rule, Docker network, Docker daemon setting, or legacy
egress-installer invocation is required or permitted by this design.

## Boundary

The runner keeps Docker's existing `bridge` network, but only the immutable
`web-screenshot` / `web_capture` route may select `public_egress`. It receives
the fixed `--cap-add=NET_ADMIN` flag; no manifest can request a capability or
network name.

Before Chromium starts, the entrypoint installs container `OUTPUT` rules that:

- allow only the resolver addresses from `/etc/resolv.conf` on port 53;
- allow only TCP 80 and 443 to public IPv4 and IPv6 destinations;
- reject loopback, private, link-local, carrier-grade NAT, documentation,
  benchmarking, multicast, reserved, ULA, IPv4-mapped, and metadata ranges;
- apply matching IPv4 and IPv6 policies; and
- fail closed if either firewall setup or rule verification fails.

The rule set blocks the packet at its actual destination IP. DNS rebinding
therefore cannot turn a browser request that passed a preflight lookup into a
connection to the Docker gateway, host, metadata service, or another private
address.

The entrypoint then uses `setpriv` to clear the capability bounding set and
executes the runner as the dedicated non-root user. `capture.js` never runs
with `NET_ADMIN`.

## Browser and output behavior

The existing exact-host document policy remains in force. The follow-up fix
makes `public_egress` unavailable to generic Packs, closes every non-primary
popup (including `about:blank`), and tracks a primary-document route through
the complete `route.continue()` or abort lifetime. Finalization closes/quiesces
the context, rechecks the block state, and writes at most one capture report.

The Pack, image, and runner tag move from `0.1.1` to `0.1.2` together.

## Verification

The image provides an egress self-check and CI runs it with Docker
`NET_ADMIN`. It proves all of the following:

- private IPv4 cannot connect;
- IPv6 ULA and link-local addresses cannot connect;
- metadata IPs cannot connect;
- `host.docker.internal` cannot connect, including when Docker maps it to the
  host gateway;
- the Docker bridge gateway cannot connect;
- public HTTP and HTTPS can connect;
- DNS resolution works through the configured resolver;
- a redirect target resolving to a private IP is blocked at connect time;
- popups cannot bypass policy, including `about:blank`;
- Chromium starts without `NET_ADMIN`;
- firewall initialization failure prevents the runtime from starting; and
- finalization writes output exactly once, including when a navigation begins
  during finalization.

PHP adapter tests cover the closed Pack/profile/capability mapping. Node tests
cover popup and finalization behavior. Docker integration tests cover the
actual namespace rules. If Docker is unavailable locally, those tests remain
required in Docker-capable CI and are reported as unrun rather than simulated
as passing.

## Non-goals

This change does not modify host `iptables`, create or configure Docker
networks, reuse `install_capture_egress_network.sh`, run a proxy sidecar, or
broaden egress access for other Packs.
