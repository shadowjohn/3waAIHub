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

## Managed Async Audio Jobs

三個公開 mode 都解析成固定 Pack job，client 不可傳 `pack_id`、image、entrypoint、host path 或 callback URL：

| Public mode | Pack job | GPU |
| --- | --- | --- |
| `audio_cleanup` | `audio-cleanup/cleanup` | `gpu:0` |
| `speech_transcribe` | `whisper-asr/transcribe` | `gpu:0` |
| `voice_generate` | `tts-voxcpm2/synthesize` | `gpu:0` |

`tasks` 是唯一的業務 queue；`runtime_runs` 只記錄一次執行嘗試。worker 先以短 SQLite transaction 取得 `gpu:0`，再做 Docker、ffprobe、hash 與檔案操作。run ID、worker ID、lease token 必須同時符合，才可 heartbeat、完成、釋放或封鎖 GPU。

成功與失敗都必須先停止並移除容器、確認已記錄的 owned GPU PID 消失，才可釋放 `gpu:0`。任一 cleanup 證據不完整時回 `cleanup_failed`，並將 resource 留在 `blocked`；不得改由另一個 queue、daemon 或 lock file 繼續執行。

Legacy `asr` / `tts` 只供 readiness、smoke 與短人工樣本。實際 inference 的同步請求同樣以 diagnostic `runtime_runs` 和 `gpu:0` lease fence 執行；上限是 `sync_max_duration_seconds=30`、Pack upload bound 與 `sync_concurrency=1`。callback 或 `source_artifact_id` 一律回 `async_required`，忙碌時回 `sync_busy`，Gateway 不會暗中建立 task。
