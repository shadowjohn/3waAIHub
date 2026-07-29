# Edge TTS 真實 L5 外部驗收

這是管理者主動執行的公開 API 驗收，不是一般單元測試。它只送出程式內固定的短句，
仍不可視為允許傳送機密內容。驗收不直接呼叫容器或 Pack 執行器，也不需要 GPU：
Edge TTS 必須使用 CPU 佇列，且本機 Hub 會確認目標 task 沒有 `gpu:0` lease 或受管
執行期 PID。

## 前置條件

1. 在 `admin/packs.php` 確認 `edge-tts` service 已 `installed`、`enabled`、`running`，
   且安裝已產生至少一個驗證通過的示範音檔。
2. 準備只用於此驗收的 Bearer token，至少有 `edge_tts`、`task_status`、
   `task_result`、`artifact`、`task_artifacts_ack` 權限。
3. 準備可由執行 CLI 的主機連到的公開端點，路徑必須剛好以 `api.php` 或
   `cluster_api.php` 結尾，不能包含查詢字串、片段或使用者資訊；並確認本機有
   `ffprobe`。

非同步驗收前，scheduler-managed 的 command queue 與 task queue 必須已由既有排程
啟用；否則工作只會停在佇列而造成驗收逾時。不要手動執行全域
`scripts/command_worker.php` 或 `scripts/task_worker.php` claimer 來排除阻塞，因為它們
可能取得無關工作。應修復既有排程，再重新執行本驗收。

一般 `scripts/run_tests.php` 不會執行外部測試。一般 `scripts/benchmark.php` 也會對
`external_acceptance` 採取失敗封鎖：不發出網路請求、不建立 task；它不是這支驗收
的替代品。

部署時採用 container-local、fail-closed 的 egress firewall。只有受信任的
`entrypoint` 初始化期間使用 `NET_ADMIN`；規則驗證後會移除 capability，並以
non-root 使用者執行合成與 demo generator。它不修改 host firewall、Docker daemon
或 Docker network。

## 以環境變數執行

token 只能透過環境變數提供：不要當作 CLI 參數、不要提交到版本庫、不要貼入工單或
共用終端機記錄；可行時先以秘密管理工具或受保護的命令列環境載入，以避免命令歷程。
不要啟用 `set -x`。

```bash
AIHUB_EDGE_TTS_ACCEPTANCE_BASE_URL='https://hub.example/3waAIHub/api.php' \
AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN='…' \
php scripts/edge_tts_acceptance.php
```

Cluster 改用同一服務對外的 `cluster_api.php`，例如
`https://cluster.example/3waAIHub/cluster_api.php`；其餘命令不變。兩個環境變數以外
沒有設定或 token CLI 選項。CLI 會驗證 URL 形狀，並以 Bearer 標頭發出不跟隨
redirect（`non-redirect`）的請求。

## 驗收閉環與輸出

成功路徑固定為：認證 GET 聲線清單 → 讀第一個聲線的 `demo_url` MP3 →
POST `edge_tts` 非同步合成（`include_subtitles=true`）→ `task_status` →
`task_result` → 下載並驗證五個 artifact → 每個 artifact `task_artifacts_ack`。

五個 artifact 必須全數存在且型別正確；列出的標準 MIME 之外，字幕只接受下列相容
MIME：

- `generated_audio` / `audio/mpeg`，以 `ffprobe` 驗證。
- `synthesis_metadata` / `application/json`。
- `subtitle_vtt` / `text/vtt`；也接受 `text/plain`。
- `subtitle_srt` / `application/x-subrip`；也接受 `text/plain`、`text/x-subrip`、
  `text/srt`。
- `speech_timeline` / `application/json`。

若 result 宣告大小或 SHA-256，CLI 會比對；也會解析中繼資料、VTT、SRT 與時間軸。
成功才寫入 `edge_tts_async_complete` PASS benchmark 紀錄。終端輸出與保存的結果都經
安全去識別：不包含 token、URL、提交文字、task ID、artifact ID、hash 或回應本文。

失敗只會以其中一個受限錯誤碼結束：

- `edge_tts_acceptance_config_invalid`
- `edge_tts_acceptance_list_demo_failed`
- `edge_tts_acceptance_submission_failed`
- `edge_tts_acceptance_task_failed`
- `edge_tts_acceptance_artifact_invalid`

## 查看準備狀態與安全排查

在後台開啟 `admin/pack_readiness.php?pack_id=edge-tts`，確認
`real_inference_benchmark_passed` 與其他 L5 檢查項；最近的驗收紀錄可在
`admin/benchmarks.php` 查看。此頁只會檢查 `L5 contract` 與已保存的 `benchmark`，不能
證明 service 已 `installed/enabled/running`；必須另在 `admin/packs.php` 確認服務狀態。
不要用 UI 顯示以外的 task、token 或 URL 資料補寫 benchmark。

若失敗，先確認 service 仍 running、token mode/權限正確、公開基底 URL 指向正確
`api.php` 或 `cluster_api.php`，以及安裝時已有生成的示範音檔。修復後重新執行同一指令；
不要貼出 HTTP 回應本文、token、完整 URL、task/artifact ID 或生成音檔來排查。
