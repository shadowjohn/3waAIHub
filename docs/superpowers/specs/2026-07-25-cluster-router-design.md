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
- The Router is the client Token authority. It reuses the Router host's existing `api_members`, `api_tokens`, and mode permissions.
- Each target station receives a dedicated Router station Token, limited by mode and Router source IP. Customer Tokens never leave the Router.
- A station can host the Router role later, but V1 has one configured active Router. There is no election, replicated database, or automatic failover.
- V1 usage belongs to the existing account and Token only. It has no department, project, budget, or LLM input/output Token model.

## Account and Token Boundary

An administrator creates one customer account and one or more customer Tokens at the Router using the existing API member screens. Each customer Token retains its normal mode permissions and is valid only at the common entry.

Each execution station has one private Router station Token. It is shown only as masked configuration on the station detail page, is restricted to the Router source IP, and is never shown to a customer. The Router validates the customer Token, then replaces it with the selected station Token before forwarding.

Existing 3wa customer Tokens work through the first Router without replacement. Existing 5090/1080 customer Tokens remain direct-station credentials; central access receives a newly issued 3wa Token during an explicit migration window.

## Station Inventory

The Router stores a small station registry: station id, display name, public base URL, internal base URL when available, priority, enabled flag, and its private station Token.

It polls each enabled station's `api_manifest.json.php`. The manifest is already the canonical list of installed, enabled, running, healthy Pack APIs, so the Router does not probe Pack endpoints itself.

Each station adds an authenticated internal cluster-status response with only routing facts:

- snapshot time and freshness;
- GPU availability and free/total VRAM;
- active GPU leases and queued/running Pack jobs;
- current enabled service modes.

The Router polls every 10 seconds, marks an inventory stale after 30 seconds, and never selects stale or unreachable stations.

## Request Routing

1. The client calls `cluster_api.php?mode=<mode>` with its existing Router Token.
2. The Router validates token, IP policy, member permissions, method, and request-size rules before selecting a station.
3. Candidates must publish the requested mode and have a fresh status snapshot.
4. The Router ranks candidates by free VRAM, active lease/queue pressure, then configured priority. It prefers itself only when the other values tie.
5. The Router forwards the original GET, JSON body, multipart upload, or binary response using the selected station Token. It records the client member, mode, station, and route id.

Routing is decided once. After dispatch, V1 never retries the request on another station because the remote execution may already have started.

## Usage Ledger

The Router is the sole cross-station usage ledger. Each routed request records account, customer Token, mode, selected station, route id, status, elapsed time, upload bytes, and response bytes.

The account dashboard reports request count, success/failure count, current active routes, peak concurrent routes, mode, station, and transfer bytes. It does not count TCP connections because keep-alive would make that number misleading.

An async submit is one work request and one route. Status, result, log, cancel, and artifact follow-ups retain the same account and route; they record access count and transfer bytes, but do not create another work request. GPU seconds and measured Wh/kWh are explicitly deferred until a later station telemetry phase.

## Async Route Pinning

For async modes, the Router persists a route mapping containing Router route id, station id, remote task id, client member id, mode, and retention expiry.

The Router rewrites `status_url`, `result_url`, `log_url`, `cancel_url`, and artifact URLs so clients remain on the common entry. Follow-up calls validate the original client identity, look up the pinned station, then proxy the request with that station's Token. A station outage returns a precise unavailable error; it never causes an implicit rerun elsewhere.

## Operations

Add an admin cluster console with host cards. Each card shows station freshness, GPU/queue summary, published service count, enabled state, and recent route pressure. Operators may disable a station manually; disabled or stale stations are excluded immediately.

A host detail page lists the station's published services, per-mode readiness, recent routes, and masked Router station Token configuration. Existing API member and Token pages gain cluster usage summaries for the account and Token; no separate customer or billing model is created.

Router API logs are the customer audit record. Station logs identify the Router station Token and retain their normal local execution diagnostics.

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
