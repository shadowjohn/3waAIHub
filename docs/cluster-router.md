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

The manifest and the human docs expose only the Router contract. A cache refresh can temporarily remove an unavailable mode; retry discovery later instead of selecting a station yourself. Direct `api.php` docs remain for direct, single-node integrations, but customer Router clients use only `cluster_api.php`.

Synchronous modes return their normal Router response. An asynchronous submit returns an opaque Router `task_id`; use that value with the Router-provided `cluster_task_status`, `cluster_task_result`, `cluster_task_log`, `cluster_task_cancel`, and `cluster_artifact` templates. Do not reuse any task ID from a station. If the selected station becomes unavailable, the Router returns a stable station-unavailable response. It does not make a post-dispatch retry on another station, and it never reveals a node name, URL, port, Token, invitation, or local path.

# Operator Setup and Recovery

The Hub creates its own `data/cluster.key` the first time a Cluster role needs it. Do not put this key in a table, environment variable, ticket, chat, or log. A legacy `AIHUB_CLUSTER_SECRET_KEY` is migrated into the local key file once, then is no longer required. Move `data/` together with the Hub when preserving an installation; for a new host identity, start with a new key and pair the child nodes again.

For each 子入口節點, enable the child-node role, choose the services it may publish, and reveal its child Token only through the authenticated admin UI. Paste its short-lived `cluster_pair.php` link into `新增子節點` on the 統一入口; it transfers the existing child Token exactly once and binds it to the unified Router source IP. Operators must never paste child Tokens or pairing invitations in tickets, chat, or public logs. Configure the paired station priority and enabled state, then refresh the station cards before sending traffic. `cluster_status.php` and the `cluster_status` permission are control-plane checks, not customer endpoints.

When a station Token is regenerated, pair the node again. Then enable the unified Router role, refresh the station inventory and dashboard cards, and confirm the selected modes appear in the Router manifest. A forced refresh is optional:

```bash
php scripts/agent_manifest_smoke.php --manifest-url=https://router.example/3waAIHub/cluster_manifest.json.php
php scripts/cluster_refresh.php --force
curl -fsS https://router.example/3waAIHub/cluster_manifest.json.php
```

To safely disable a station, set it disabled, refresh the cards until its modes disappear from the Router manifest, and stop traffic before maintenance. For stale or disabled recovery, fix the child role, selected services, pairing, IP restriction, or reachability; refresh the inventory; then re-enable it and confirm the cards before routing traffic again. Do not work around a stale mode by handing customers a node endpoint.

Router usage records customer account and Token route activity plus request and response byte reporting. V1 does **not** track departments, quotas, LLM tokens, GPU minutes, or kWh.
