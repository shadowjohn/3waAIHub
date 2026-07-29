# Customer Unified Entry

Use the Router as the only customer-facing entry point:

```text
https://router.example/3waAIHub/cluster_api.php
https://router.example/3waAIHub/cluster_manifest.json.php
https://router.example/3waAIHub/cluster_public_api_docs.php
```

The 統一入口 issues customer Bearer Tokens and checks each Token's permitted modes. A customer or agent can read the discovery manifest without a Token before choosing a currently usable mode:

```bash
curl -fsS https://router.example/3waAIHub/cluster_manifest.json.php
curl -fsS "https://router.example/3waAIHub/cluster_api.php?mode=hello" \
  -H "Authorization: Bearer <CUSTOMER_TOKEN>"
```

Create the customer account and Token at the unified entry. In the Token's `Mode 權限`, select the required local modes and any visible `Cluster Router Mode` entries. Give the customer only `cluster_api.php`, its Router Token, and the public manifest/docs URLs.

The manifest and the human docs expose only the Router contract. A cache refresh can temporarily remove an unavailable mode; retry discovery later instead of selecting a station yourself. Direct `api.php` docs remain for direct, single-node integrations, but customer Router clients use only `cluster_api.php`.

Synchronous modes return their normal Router response. An asynchronous submit returns an opaque Router `task_id`; use that value with the Router-provided `cluster_task_status`, `cluster_task_result`, `cluster_task_log`, `cluster_task_cancel`, and `cluster_artifact` templates. Do not reuse any task ID from a station. If the selected station becomes unavailable, the Router returns a stable station-unavailable response. It does not make a post-dispatch retry on another station, and it never reveals a node name, URL, port, Token, invitation, or local path.

# Operator Setup and Recovery

The Hub creates its own `data/cluster.key` the first time a Cluster role needs it. Do not put this key in a table, environment variable, ticket, chat, or log. A legacy `AIHUB_CLUSTER_SECRET_KEY` is migrated into the local key file once, then is no longer required. Move `data/` together with the Hub when preserving an installation; for a new host identity, start with a new key and pair the child nodes again.

When the Router host also supplies services, enable both Cluster roles, select its running services under `子入口節點`, then press `登錄 / 更新本機服務`. Refresh its card and confirm the selected modes appear in `cluster_manifest.json.php`. This creates an in-process local station; it does not call the Router host through its public URL.

For each 子入口節點, enable the child-node role, choose the services it may publish, and reveal its child Token only through the authenticated admin UI. Paste its short-lived `cluster_pair.php` link into `新增子節點` on the 統一入口; it transfers the existing child Token exactly once and binds it to the unified Router source IP. Operators must never paste child Tokens or pairing invitations in tickets, chat, or public logs. Configure the paired station priority and enabled state, then refresh the station cards before sending traffic. `cluster_status.php` and the `cluster_status` permission are control-plane checks, not customer endpoints.

External execution nodes such as 1080 and 5090 continue to use this one-time pairing-link flow. They need no customer accounts or customer Tokens of their own.

When a station Token is regenerated, pair the node again. Then enable the unified Router role, refresh the station inventory and dashboard cards, and confirm the selected modes appear in the Router manifest. A forced refresh is optional:

```bash
php scripts/agent_manifest_smoke.php --manifest-url=https://router.example/3waAIHub/cluster_manifest.json.php
php scripts/cluster_refresh.php --force
curl -fsS https://router.example/3waAIHub/cluster_manifest.json.php
```

To safely disable a station, set it disabled, refresh the cards until its modes disappear from the Router manifest, and stop traffic before maintenance. For stale or disabled recovery, fix the child role, selected services, pairing, IP restriction, or reachability; refresh the inventory; then re-enable it and confirm the cards before routing traffic again. Do not work around a stale mode by handing customers a node endpoint.

Router usage records customer account and Token route activity plus request and response byte reporting. V1 does **not** track departments, quotas, LLM tokens, GPU minutes, or kWh.

## Station Status Contract

Cron invokes `scripts/cluster_refresh.php` once per minute. Router request
paths may also perform a due-only refresh; refresh backoff prevents repeated
failed fetches. A station is fresh only while both its manifest and status
snapshots are no more than 90 seconds old. A stale or disabled station and its
modes are removed from the routable inventory until it is enabled and a later
refresh succeeds.

`cluster_status.php` returns these required base fields to an authenticated
Router:

| Field | Meaning |
| --- | --- |
| `ok` | Successful status response; must be `true`. |
| `snapshot_at` | Station-generated status time. |
| `gpu` | Compact GPU availability, model, driver/CUDA, utilization, VRAM and temperature fields when available. |
| `active_gpu_leases` | Current unexpired GPU resource leases. |
| `queued_jobs` / `running_jobs` | Current task queue counts. |
| `modes` | Modes the child role selected and can currently publish. |

Current nodes also report these compact groups:

| Group | Fields |
| --- | --- |
| `release` | `build_id`, `commit`, `dirty`, `tag` |
| `packs` | Map of `pack_id` to Pack version |
| `runners` | Map of `pack_id` to immutable runner `digest` |
| `health` | `status`, `installed_services`, `running_services`, `failed_services`, `queued_jobs`, `running_jobs` |
| `cluster` | `aggregate`, `children_count`, `published_mode_count` |

Status reports deliberately omit node URLs, ports, filesystem paths, image
names, credentials and Tokens. Current nodes first build a release report with
live read-only Git probes. When the live commit is unavailable, they consult
the cached CLI snapshot. If the resulting release report and the other four
groups form one complete valid set, status includes all five compact groups;
otherwise it deliberately falls back to the required base payload and the
Router shows release, Pack and runner health as unknown. Older nodes that omit
the compact groups remain compatible.

## Display-only Aggregate Reservation

`cluster.aggregate=true` means the reporting Hub currently has both child-node
and unified-Router roles enabled. `children_count` and
`published_mode_count` let the Dashboard and Cluster management pages label it
as an aggregate station and show its downstream capacity.

This is a display and data-model reservation only. An aggregate Router cannot
yet pair itself upward as another Router's child, advertise its downstream
inventory upstream, or perform nested Router-to-Router forwarding. Pair the
current unified entry directly with execution nodes (and its in-process local
station) until cross-layer routing is explicitly implemented.
