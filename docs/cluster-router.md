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

Synchronous modes return their normal Router response. For asynchronous modes, keep the Router-provided status, result, log, cancel, and artifact links and poll through the Router. If the selected station becomes unavailable, the Router returns a stable station-unavailable response. It does not make a post-dispatch retry on another station, and it never reveals a node name, URL, port, Token, invitation, or local path.

# Operator Setup and Recovery

Set the same `AIHUB_CLUSTER_SECRET_KEY` in the web-server environment and every CLI environment that runs Hub commands. Generate it once, then keep the value out of tickets, chat, and logs:

```bash
openssl rand -hex 32
export AIHUB_CLUSTER_SECRET_KEY=<GENERATED_64_HEX>
```

For each 子入口節點, enable the child-node role, choose the services it may publish, and reveal its child Token only through the authenticated admin UI. Pair its short-lived `cluster_pair.php` link with the 統一入口. The resulting one-time station Token must be restricted to the Router source IP. `cluster_status.php` and the `cluster_status` permission are control-plane checks, not customer endpoints.

When a station Token is regenerated, pair the node again. Then enable the unified Router role, refresh the station inventory and dashboard cards, and confirm the selected modes appear in the Router manifest. A forced refresh is optional:

```bash
AIHUB_CLUSTER_SECRET_KEY=<GENERATED_64_HEX> php scripts/cluster_refresh.php --force
curl -fsS https://router.example/3waAIHub/cluster_manifest.json.php
```

If a mode is stale or a station is disabled, fix the child role, selected services, pairing, IP restriction, or reachability; refresh the inventory; then re-enable it. Do not work around a stale mode by handing customers a node endpoint.

Router usage is recorded by customer account and Token for the request/response scope. V1 does **not** track departments, quotas, LLM tokens, GPU minutes, or kWh.
