# Whisper ASR 常駐服務與 GPU 排程

`speech_transcribe` 可以把非同步工作交給既有的 `asr-main`，由常駐模型處理後，仍由 Hub 驗證並登錄 `transcript_json`、`transcription_report`、字幕與說話者時間軸等受管 artifacts。

## 啟用常駐模式

在 `asr-main` 的服務設定中調整：

- `WHISPER_EXECUTION_MODE=resident`：非同步工作改走常駐服務；預設仍是 `isolated`，避免既有安裝在未確認資源前改變行為。
- `WHISPER_GPU_SHORTAGE_POLICY=wait`：保留 GPU 模式；容量不足時等待下一次排程。
- `WHISPER_GPU_SHORTAGE_POLICY=cpu`：常駐 ASR 固定使用 CPU / int8，不取得 GPU lease，適合 GPU 已長期放置 VoxCPM2、BiRefNet、攝影機 ffmpeg 等工作的主機。
- `WHISPER_RESIDENT_MIN_FREE_VRAM_MB`：模型已在 GPU 常駐時仍需保留的 VRAM 下限。

儲存後依畫面提示重啟 `asr-main`，再執行健康檢查。CPU 策略是明確的服務設定，不會在工作執行中偷偷切換裝置。

## 等待狀態

`task_status` 固定回傳 `status`、`progress`、`message`。等待資源時另有：

```json
{
  "status": "waiting_gpu",
  "progress": 0,
  "message": "Waiting for GPU memory used by another process.",
  "waiting_reason": "unmanaged_gpu_process",
  "required_vram_mb": 10000,
  "free_vram_mb": 768,
  "retry_after_seconds": 30,
  "gpu_processes": [
    {
      "pid": 731,
      "process_name": "ffmpeg",
      "used_vram_mb": 512,
      "classification": "external"
    }
  ]
}
```

`retry_after_seconds` 是「下次排程檢查」的預估時間，不是保證完成時間。程序清單最多 32 筆，只公開程序名稱、PID、VRAM 與分類，不含命令列或環境變數。

常見 `waiting_reason`：

| 值 | 說明 |
| --- | --- |
| `gpu_unavailable` | Hub 的 GPU lease 目前已被其他受管工作持有。 |
| `insufficient_vram` | 可用 VRAM 未達模型需求與安全邊界。 |
| `unmanaged_gpu_process` | 外部程序或常駐程序正在使用 VRAM；查看 `gpu_processes`。 |
| `resident_busy` | 常駐 ASR 正在處理另一筆工作。 |
| `resident_unknown` | 常駐服務容量端點暫時無法確認。 |
| `resident_service_unavailable` | 已選常駐模式，但 `asr-main` 未完成安裝、啟用或執行。 |

Hub 不會因高優先序工作而停止 ffmpeg、模型服務或其他外部程序，也不會自動驅逐常駐模型。

## 優先序與 Cluster

提交非同步 Pack 工作時可傳 `priority=0..100`，預設為 `0`；同一佇列會先取較高優先序，再依建立時間與 task ID 排序。手動 retry 會保留原優先序。

透過 Cluster Router 提交時，Router 會在建立子節點工作之前優先選擇新鮮、支援該模式且沒有 GPU lease／排隊壓力的節點。工作一旦由節點接受便固定在該節點，避免 artifact 所有權與重試冪等性遭破壞；節點執行中不會再漂移到另一台。

因此操作策略是：

1. 可接受延遲的工作使用 `wait` 和較低 `priority`。
2. GPU 長期被占用且可接受較慢 ASR 時，使用常駐 `cpu`。
3. 有多個節點時，從 Cluster Router 提交，讓 Router 在工作建立前選擇較空閒的節點。

## Artifact 與 ID

常駐模式不會直接把服務內路徑交給 client。結果仍依標準流程：submit → `task_status` → `task_result` → 下載 managed artifact → ACK。

Router 的 `task_id` 是 opaque string，必須原樣保存，不能轉成整數。直接呼叫節點 `api.php` 得到的 native task ID 才是數字；兩個命名空間不可混用。
