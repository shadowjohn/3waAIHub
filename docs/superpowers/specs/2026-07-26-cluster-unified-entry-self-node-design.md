# Cluster Unified Entry Self Node and Token Permissions Design

## Goal

Make the unified Router usable as the only customer-facing API entry: system administrators can grant every currently routable Mode to a Router Token, and the Router host can contribute its own running services without an HTTP loopback or a second Hub installation.

## Selected Approach

Reuse the existing `cluster_stations`, child-node Token, Router dispatch, and `AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY` setting. Do not add tables, a new customer identity, a second Token type, or a new transport.

The alternatives are deliberately not used:

- Requiring the Router to paste its own pairing link would depend on public-DNS hairpinning and would bind the child Token to an incidental source IP.
- Treating all local `api.php` Modes as Router Modes would publish services that the Router has not explicitly selected or verified healthy.

## Local Router Station

When both Cluster roles are enabled, the Cluster admin page presents one explicit action to register the current Hub as the Router's local execution station. The action requires a configured child node and refuses to overwrite a child already paired to another Router.

Registration is idempotent. It saves the existing local child Token into the existing encrypted station record, marks that station key as the Router self station, records the local Router name, and restricts the child Token to `127.0.0.1` with the existing `cluster router` rule. It consumes no invitation and does not make an HTTP request.

Self-station inventory refresh reads the existing local public manifest builder and local cluster-status payload in-process. It stores the same compact manifest/status snapshots used for remote stations, so normal freshness, selection, public docs, and customer routing continue to use one station model. Dispatch already recognizes the configured self station and invokes the existing gateway directly with the loopback-bound child Token.

If the local child node is disabled, its child Token remains revoked by the existing lifecycle; its self station is no longer eligible because direct dispatch fails closed. Explicit station deletion continues to remove the station and its route records.

## Router Token Permissions

`admin/api_token_permissions.php` retains its existing local service, task, photo, and audio sections. When the Router role is enabled, it adds a `Cluster Router Mode` section for the modes in the current Router public manifest that are not already shown elsewhere.

The list is computed from cached, enabled, fresh station inventory only. Rendering the permissions page does not make network calls or reveal a station name, URL, or station Token. Saving permissions continues to use the existing mode-permission writer, which already supports remote-only modes without a local service row.

## Error Handling and Verification

Local registration returns a compact admin error when either role is missing or the child is paired to another Router; it performs no partial update. A stale or disabled remote station remains absent from both public Router discovery and the Router permission section.

Tests prove that local registration creates a verified loopback peer and a self station usable by direct dispatch, self refresh does not call the remote fetcher, and a fresh remote-only Mode is surfaced to the permissions page through the Router mode helper. Existing Cluster routing, permission, and full-suite tests remain green.
