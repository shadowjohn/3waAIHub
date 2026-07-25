# Cluster Router Design

## Goal

Expose one client API entry that routes a permitted request to a healthy 3waAIHub station. A Hub may enable the Router role; the first Router runs on 3wa, while 5090 and 1080 remain independent execution stations.

The initial stations are:

| Station | Base URL | GPU |
| --- | --- | --- |
| 5090 | `https://nature.focusit.tw/3waAIHub/` | 32 GB |
| 3wa | `https://3wa.tw/3waAIHub/` | 16 GB |
| 1080 | `http://192.168.1.106/3waAIHub/` | 8 GB |

## Boundaries

- Add a new public `cluster_api.php` entry. Existing station-local `api.php` remains unchanged and can still be used for maintenance or direct integrations.
- Only a system administrator can enable either Cluster role. `Enable child entry node` publishes a one-time pairing invitation; `Enable unified entry` enables the Router and the child-node management console.
- The Router is the client Token authority. It reuses the Router host's existing `api_members`, `api_tokens`, and mode permissions.
- Each target station receives a dedicated Router station Token, limited by mode and Router source IP. Customer Tokens never leave the Router.
- A station can host the Router role later, but V1 has one configured active Router. There is no election, replicated database, or automatic failover.
- V1 usage belongs to the existing account and Token only. It has no department, project, budget, or LLM input/output Token model.

## Account and Token Boundary

An administrator creates one customer account and one or more customer Tokens at the Router using the existing API member screens. Each customer Token retains its normal mode permissions and is valid only at the common entry.

Each execution station creates one private Router station Token when its child-node role is enabled. It is restricted to the paired Router source IP and encrypted by both child and Router. The child-node system-admin page may show the complete Token for maintenance; Router station detail pages show only a mask, and it is never shown to a customer. The Router validates the customer Token, then replaces it with the selected station Token before forwarding.

Existing 3wa customer Tokens work through the first Router without replacement. Existing 5090/1080 customer Tokens remain direct-station credentials; central access receives a newly issued 3wa Token during an explicit migration window.

## Station Inventory

When a system administrator enables `Enable child entry node`, that station creates a dedicated child Token, stores it encrypted, and shows its complete copyable value only on that system-admin page. The administrator also checks which currently installed/running service modes the child is willing to supply; `cluster_status` is always included. The child reports only that selected mode list to the Router. The page also shows a copyable pairing link. The link contains a short-lived, one-time invitation in its URL fragment, so normal link navigation does not send the invitation to a web-server log. A system administrator at an enabled unified entry pastes the link into its child-node form. The Router exchanges the invitation in a request header; the child IP-restricts the selected Token to the Router source and returns its station description and Token over the one-time pairing exchange. The Router stores its copy encrypted and immediately invalidates the invitation. Regenerating a child Token revokes the old Token and requires the unified entry to pair again.

The Router stores the paired station's id, display name, public base URL, internal base URL when available, priority, enabled flag, and encrypted private station Token. V1 has one paired unified entry per child node; re-pairing revokes the previous child Token and creates a fresh invitation.

It polls each enabled station's `api_manifest.json.php`. The manifest is already the canonical list of installed, enabled, running, healthy Pack APIs, so the Router does not probe Pack endpoints itself.

Each station adds an authenticated internal cluster-status response with only routing facts:

- snapshot time and freshness;
- GPU availability and free/total VRAM;
- active GPU leases and queued/running Pack jobs;
- current enabled service modes.

The Router refreshes due station inventory at most once every 10 seconds during Router traffic, an explicit console refresh, or the optional refresh command; it marks an inventory stale after 30 seconds and never selects stale or unreachable stations.

## Request Routing

1. The client calls `cluster_api.php?mode=<mode>` with its existing Router Token.
2. The Router validates token, IP policy, member permissions, method, and request-size rules before selecting a station.
3. Candidates must publish the requested mode and have a fresh status snapshot.
4. The Router normally selects the highest configured performance priority that is healthy (for the first deployment, 3wa before 1080 when both offer the same mode). It overflows to a lower-priority station only when the preferred station has insufficient free VRAM, an active GPU lease, or queue pressure. Equal eligible stations use free VRAM as the final tie-breaker; a Router host is not implicitly preferred.
5. The Router forwards the original GET, JSON body, multipart upload, or binary response using the selected station Token. It records the client member, mode, station, and route id.

Routing is decided once. After dispatch, V1 never retries the request on another station because the remote execution may already have started.

A Router selecting itself calls the existing Gateway directly rather than opening a second HTTP request to its own web server. V1 limits live proxy transfers and buffered remote response size so a burst fails cleanly with a Router capacity error instead of consuming unbounded PHP workers or memory; full artifact streaming is deferred until real artifact sizes require it.

## Usage Ledger

The Router is the sole cross-station usage ledger. Each routed request records account, customer Token, mode, selected station, route id, status, elapsed time, upload bytes, and response bytes.

The account dashboard reports request count, success/failure count, current active routes, peak concurrent routes, mode, station, and transfer bytes. It does not count TCP connections because keep-alive would make that number misleading.

An async submit is one work request and one route. Status, result, log, cancel, and artifact follow-ups retain the same account and route; they record access count and transfer bytes, but do not create another work request. GPU seconds and measured Wh/kWh are explicitly deferred until a later station telemetry phase.

## Async Route Pinning

For async modes, the Router persists a route mapping containing Router route id, station id, remote task id, client member id, mode, and retention expiry.

The Router rewrites `status_url`, `result_url`, `log_url`, `cancel_url`, and artifact URLs so clients remain on the common entry. Follow-up calls validate the original client identity, look up the pinned station, then proxy the request with that station's Token. A station outage returns a precise unavailable error; it never causes an implicit rerun elsewhere.

## Operations

Add an admin cluster console with the two role toggles first. When child-node role is on, it shows the complete copyable child Token, selected-mode checkboxes, copyable invitation link, and pairing state. When unified-entry role is on, it shows a paste field for child-node links plus host cards. Each card shows station freshness, GPU/queue summary, published service count, enabled state, and recent route pressure. Operators may disable a station manually; disabled or stale stations are excluded immediately.

A host detail page lists the station's published services, per-mode readiness, recent routes, and masked Router station Token configuration. Existing API member and Token pages gain cluster usage summaries for the account and Token; no separate customer or billing model is created.

Router API logs are the customer audit record. Station logs identify the Router station Token and retain their normal local execution diagnostics.

## Documentation

Ship a separate unified-entry client guide. It documents only the common `cluster_api.php` URL, Router-issued customer Token, live service inventory, synchronous calls, and pinned async follow-ups. It must not expose station URLs, Router station Tokens, pairing invitations, or operator-only routing policy. The Router's human API page and machine-readable manifest expose only modes that are currently safe to route; the admin console links to a separate operations section for the two role toggles, pairing, station registry, private Tokens, and recovery procedures.

## Deliberately Deferred

- departments, projects, budgets, quotas, or LLM input/output Token accounting;
- client Token synchronization or importing Tokens from 5090/1080;
- automatic Router leader election or standby promotion;
- distributed database, shared task queue, or cross-station artifact replication;
- automatic retry/failover after a request is dispatched;
- demand-based model download, container recovery, or GPU eviction during routing.

## Acceptance

- One Router Token can call a permitted mode through `cluster_api.php` and the selected station is recorded.
- A mode unavailable on 3wa can route to a fresh 5090 or 1080 station without exposing a station Token.
- Disabled, stale, unauthorized, or method-incompatible requests fail before remote dispatch.
- JSON, multipart, and binary sync responses retain their public contract through the Router.
- Async submit and all returned follow-up URLs stay on the Router and remain pinned to the selected station.
- Account and Token reports attribute each work request and transfer byte to the selected station without exposing station Tokens.
- No inference endpoint is called by inventory polling or status collection.
