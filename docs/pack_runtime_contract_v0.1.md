# Pack Runtime Contract v0.1

3waAIHub Pack 可以是 AI 服務，也可以是一般本地批次工具。Hub 不假設 Pack 一定需要 GPU、模型、Python 或 HTTP。

## Manifest 欄位

```json
{
  "runtime_contract": "0.1",
  "runtime_modes": ["service", "job"],
  "capabilities": {
    "local_job": true,
    "managed_job": false,
    "http_service": true
  }
}
```

- `runtime_modes=service`：常駐服務，沿用現有 service instance、generated compose、health check 與 Gateway。
- `runtime_modes=job`：一次性本地工作，遵守 Local Job Contract。
- `runtime_level` 仍表示整合成熟度，例如 `L5-benchmark-ready`，不代表 GPU 或 REST API。

## Runtime Observability v0.1

`bin/aihub-run` 每次執行會建立一筆 Run：

- SQLite：`runtime_runs`
- SQLite：`runtime_resource_samples`
- Workspace：`runtime/run.json`
- Workspace：`runtime/resource.ndjson`
- Workspace：`runtime/events.ndjson`

薄版只保證 start/end resource sample。GPU 欄位允許 `null`，因為 GIS、GDAL、地籍清洗 Pack 不一定需要 GPU。

## 目前邊界

不做完整排程器、不做多主機、不做自動 retry、不做 GPU lock。這些屬於後續 L3 Managed Job。
