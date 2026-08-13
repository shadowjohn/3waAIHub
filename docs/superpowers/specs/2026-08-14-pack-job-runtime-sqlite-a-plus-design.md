# Pack-job/runtime SQLite A+ 設計

日期：2026-08-14
狀態：已確認設計，待 implementation plan

## 背景

3waAIHub 的 pack-job worker 曾在 Whisper container 啟動前，因 SQLite writer lock 競爭而以 `database is locked` 失敗。現有 `hub_sqlite_begin_immediate()` 已提供集中式、有限次數的鎖定重試，適合作為最後一道保險，但不能把 retry 當成常態排隊機制。

現況盤點顯示：

- pack-job resident heartbeat 預設每 10 秒執行，runtime lease 預設 60 秒。
- 非 GPU heartbeat 已是單一 autocommit `UPDATE`，但每次 tick 都會寫入。
- GPU heartbeat 的 validation 與 expiry 計算已在 transaction 外，transaction 內只有 runtime 與 GPU lease 兩個 fence-protected `UPDATE`，但每次 tick 都會 `BEGIN IMMEDIATE`。
- task claim 保有 PHP `candidateFilter` 語意，不適合在這一階段直接改成單一 `UPDATE ... RETURNING`。
- 目前沒有依 runtime action 分類的 transaction duration、lock wait 或 retry telemetry。

本階段先回答兩個問題：SQLite contention 主要出現在 pack-job/runtime 的哪個 action，以及 heartbeat 降低寫入後改善多少。

## 目標

Phase 1 A+ 包含：

1. 對 pack-job/runtime 的 claim、heartbeat、finish、recovery 加入 action-level NDJSON telemetry。
2. 保留 10 秒 process health tick，但只有 lease 剩餘時間小於等於 30 秒時才寫 heartbeat。
3. 非 GPU heartbeat 維持單一 autocommit conditional `UPDATE`。
4. GPU heartbeat 暫時保留短 `BEGIN IMMEDIATE`，transaction 內只執行兩個 `UPDATE`。
5. 保留現有 task fence、cancel、finish 與 recovery 語意。
6. 提供串流式 CLI，按 action/variant 彙整 transaction 與 contention 分布。

## 非目標

本階段不會：

- 改寫 task claim 的 selection 或 `candidateFilter` 語意。
- 將所有 callback、outbox、cluster refresh 或其他 task type 納入 telemetry。
- 調整目前的 busy timeout 或 retry policy。
- 導入 single-writer coordinator。
- 遷移 PostgreSQL。
- 為 telemetry 建立 SQLite table。

## 選擇的方案

採用「明確埋點＋runner 本地 renewal state」。四條 hot path 各自在既有 transaction 邊界計時，再於 DB 操作完全結束後交給薄 NDJSON emitter。heartbeat state 使用共享的 PHP array reference，不建立通用 transaction wrapper 或 state class。

未採用的方案：

- 共用 transaction telemetry wrapper：資料一致，但會同時重構四條敏感路徑，Phase 1 修改面過大。
- 外部 SQLite profiler：程式改動較少，但無法可靠取得 action attribution、retry 與 skipped heartbeat。
- 每 10 秒執行 `UPDATE ... WHERE lease_expires_at <= threshold`：即使 affected rows 為零，仍會執行 write statement，無法達成「skip 時不碰 writer」的目標。

## Telemetry 儲存

Telemetry 寫入 SQLite 之外的每日 NDJSON：

```text
data/logs/runtime-telemetry-2026-08-14.ndjson
```

Emitter 規則：

- 每個 event 以一次 append 寫入恰好一行 JSON。
- 使用 append file handle 與短 `LOCK_EX`，不呼叫 `fsync`。
- transaction path 只收集記憶體中的時間與計數；commit、rollback 或 autocommit `UPDATE` 返回後才 encode 與 append。
- emitter 不掃目錄、不刪檔，也不建立額外 DB 記錄。
- telemetry JSON encode、open、lock 或 append 失敗不得改變 task outcome；只能透過既有 process error log 留下簡短、無敏感資料的診斷。
- event 不記錄 task input、token、上傳路徑、輸出路徑或 exception stack。

## Event schema

每行使用固定 `schema_version=1`：

```json
{
  "schema_version": 1,
  "observed_at": "2026-08-14T02:10:30.123456+08:00",
  "action": "heartbeat",
  "variant": "gpu",
  "outcome": "committed",
  "tx_mode": "immediate",
  "tx_begin_at": "2026-08-14T02:10:30.110000+08:00",
  "tx_commit_at": "2026-08-14T02:10:30.120000+08:00",
  "pre_tx_ms": 0.3,
  "lock_wait_ms": 8.1,
  "lock_wait_kind": "begin_immediate",
  "tx_ms": 1.9,
  "post_tx_ms": 0.2,
  "total_ms": 10.5,
  "retry_count": 0,
  "skipped_ticks": 2
}
```

必填分類：

| action | variant | 邊界 |
|---|---|---|
| `claim` | `task` | `hub_claim_next_task()` 成功搶到 pack-job 的 queue claim |
| `claim` | `runtime` | `hub_pack_job_claim_runtime()` |
| `claim` | `gpu` | pack-job 的 GPU lease acquisition |
| `heartbeat` | `cpu` | 非 GPU runtime renewal write |
| `heartbeat` | `gpu` | runtime 與 GPU lease renewal transaction |
| `finish` | `success` | pack-job success terminal transaction |
| `finish` | `failure` | pack-job failed/cancelled/timed-out terminal transaction |
| `recovery` | `runtime` | 不含 GPU lease 的 pack-job lost-fence reconcile transaction |
| `recovery` | `gpu` | 含 GPU lease mutation 的 pack-job lost-fence reconcile transaction |

每個 DB transaction 只 emit 一個 event。Recovery 依該 transaction 是否包含 GPU lease 擇一使用 `runtime` 或 `gpu`，不得為同一 transaction 重複 emit 兩種 variant。

`finish` telemetry 量測既有 terminal transaction 的完整 DB 範圍，包括 artifact metadata、task terminal state、hold release 與 callback delivery enqueue；不涵蓋 transaction 外的 artifact validation、file handoff 或 cleanup。Phase 1 不改變這個 boundary，先用數據判斷是否需要瘦身。

Outcome 至少包含：

- `committed`
- `rolled_back`
- `fence_lost`
- `lock_exhausted`
- `failed`

## Timing 語意

- `pre_tx_ms`：action 開始至呼叫 begin 或 autocommit write 前。
- `tx_begin_at`：transaction 成功開啟的時間；autocommit 路徑為 write 開始時間。
- `tx_commit_at`：commit、rollback 或 autocommit write 返回時間。
- `tx_ms`：transaction 開啟至 commit/rollback 返回；autocommit 路徑為整個 write execute 時間。
- `post_tx_ms`：DB 操作結束至準備 emit event 前。
- `total_ms`：整個 action 的 wall time。
- `retry_count`：第一次嘗試之後實際發生的 retry 次數。

`lock_wait_ms` 的精度依 transaction mode 區分：

- `lock_wait_kind=begin_immediate`：從呼叫 `BEGIN IMMEDIATE` 到成功返回，代表實際取得 writer transaction 的等待時間。
- `lock_wait_kind=first_write_upper_bound`：autocommit 或 deferred transaction 的第一次 mutating statement duration；PDO 無法分離 SQLite 內部 lock wait 與 statement execution，因此這是上限，不宣稱是假精準的 lock-only 時間。
- `lock_wait_kind=none`：沒有可量測的 writer-lock acquisition。

對 autocommit/deferred transaction，`lock_wait_ms` 可能與 `tx_ms` 重疊，因此 `total_ms` 不應由各 timing 欄位直接相加。對 `BEGIN IMMEDIATE`，lock wait 發生在 transaction 成功開啟前，才可近似視為不重疊。

現有 `hub_sqlite_begin_immediate()` 可接受選用的 by-reference stats array，回報 wait 與 retry；未傳入 stats 的既有 caller 行為不變。heartbeat 暫時保留目前 raw `BEGIN IMMEDIATE`，不藉此階段改變 retry 行為。

## Shared heartbeat state

每個 pack-job execution 建立一份共享可變 state：

```php
$heartbeatState = [
    'runtime_expires_at' => $runtimeExpiresAt,
    'gpu_expires_at' => $gpuExpiresAt,
    'skipped_ticks' => 0,
];
```

所有 closure 必須明確使用 reference：

```php
$heartbeat = function () use (&$heartbeatState): ?string {
    return hub_pack_job_tick(/* existing arguments */, $heartbeatState);
};
```

`hub_pack_job_tick()` 同樣以 `array &$heartbeatState` 接收並更新 state。`hub_run_pack_job_task()` 內 closure、正常完成、取消、timeout 與 exception 路徑的所有 tick 都必須使用同一個 reference，不能重新從啟動時的 `$run` 或 `$gpuLease` 快照建立副本。

## Renewal 判斷

固定條件：

- process health tick：10 秒。
- production runtime lease：60 秒。
- renewal threshold：30 秒。

非 GPU 使用 `runtime_expires_at`；GPU 使用 runtime 與 GPU expiry 的較早者。expiry 遺失、格式錯誤或無法解析時採 fail-safe，立即嘗試 renewal。lease 小於等於 30 秒時每次 tick 都 renewal，不為效能縮小安全邊界。

剩餘時間大於 30 秒時：

- 不執行 heartbeat SQLite write。
- 不開 write transaction。
- `skipped_ticks++`。
- 保留既有 cancellation 與 fence read，維持取消反應時間；這些 WAL read 不屬於 writer pressure。

剩餘時間小於等於 30 秒時執行 renewal。

## Non-GPU heartbeat

非 GPU heartbeat 保持單一 conditional autocommit `UPDATE`。WHERE predicate 保留 run ID、lease token、active state 與尚未過期 lease 的既有 fence 語意。

成功 write 返回後才更新 memory expiry。affected rows 不等於 1 時不更新 memory，維持既有 `fence_lost` 行為。

## GPU heartbeat atomicity

GPU renewal 每次只計算一個 `$newExpiry`：

```php
$newExpiry = $now + $leaseSeconds;
```

同一值用於 runtime row、GPU lease row，以及 commit 後的 memory state：

```text
BEGIN IMMEDIATE
UPDATE runtime_runs ... lease_expires_at = newExpiry ... fence
UPDATE runtime_resource_leases ... lease_expires_at = newExpiry ... fence
COMMIT
update shared memory state to newExpiry
emit telemetry
```

兩個 UPDATE 都必須 affected rows = 1 才可 commit。任一 fence、statement、commit 或 rollback 失敗時：

- 依既有語意 rollback。
- 不更新任何 memory expiry。
- 回傳既有 `fence_lost`／recovery outcome。
- transaction/rollback 完全結束後才 emit failure telemetry。

成功 commit 後才把 `runtime_expires_at` 與 `gpu_expires_at` 同時設為該次實際寫入的 `$newExpiry`。

## Skipped tick accounting

heartbeat renewal 成功後，event 帶上目前 `skipped_ticks`，emit 嘗試完成後將 execution-local counter 歸零。Telemetry append 失敗仍歸零，避免無限重複累積；task outcome 不受影響。

短任務可能在下一次 renewal 前完成。正常 finish、failure、cancel、timeout 或 exception 終止 event 必須帶上尚未被 heartbeat event 消耗的 `skipped_ticks`，然後歸零。如此 summary 可計算：

- `heartbeat_ticks_total = heartbeat event count + skipped_ticks`
- `renewal_attempts = heartbeat event count`
- `renewal_commits = committed heartbeat events`
- `skipped_ticks`
- 實際 writer traffic reduction

不為每個 skip 額外 emit event，也不為補統計增加 DB write。

## CLI summary

入口：

```bash
php scripts/runtime_telemetry_summary.php --since="1 hour"
```

CLI 行為：

- 驗證 `--since` 字串並換算查詢起點，終點為執行當下。
- 由起訖日期直接組出所需的每日固定檔名，只開啟時間範圍內可能需要的檔案。
- 不掃描 telemetry 目錄、不讀取無關日期。
- 使用 `fgets()` 逐行處理，不把完整 NDJSON 或完整 event list 載入 memory。
- 只為每個 `action + variant` 保存計算 quantile 所需的 numeric samples；count、lock、retry、exhausted、skipped 使用增量 accumulator。
- malformed、未知 schema 或欄位不合法的行略過，並輸出 `invalid_lines`；單行損壞不使整份摘要失敗。
- quantile 在每個 group 內獨立計算。

輸出欄位：

```text
action  variant  count  p50_tx  p95_tx  p99_tx  lock>0  retries  exhausted  skipped
```

## Retention

Telemetry 固定保留 7 個日曆日。既有 retention cron 統一清理；heartbeat、claim、finish、recovery 與 emitter 不做目錄掃描或刪檔。

Cleaner 只處理符合下列固定名稱的 regular file：

```text
runtime-telemetry-YYYY-MM-DD.ndjson
```

清理時不得跟隨 symlink，且必須驗證目標仍位於 `HUB_LOG_DIR`。保留當日與前 6 個日期，刪除更早的合法 telemetry file。其他 log 不受影響。

## 測試

### Renewal cadence

使用 fake clock 驗證 60 秒 lease、10 秒 tick、30 秒 threshold：

```text
t=10 skip
t=20 skip
t=30 renew
t=40 skip
t=50 skip
t=60 renew
```

每分鐘應有約 2 次 renewal 與 4 次 skip，writer renewal 至少降低 60%，目標約 67%。

### Shared mutable state

- 第一次 due tick 成功 renewal。
- 第二次 tick 必須看到 commit 後的新 expiry 並 skip。
- 若 closure capture 或函式參數失去 reference，第二次 DB write 會使測試失敗。

### GPU atomic renewal

- runtime row、GPU lease row 與 memory state 得到完全相同的 `$newExpiry`。
- 第二個 fence UPDATE 失敗時，兩張表 rollback、memory state 不動並回傳 `fence_lost`。
- commit 完成前不得 emit success event。

### Fail-safe

- expiry 遺失、格式錯誤或無法解析時必須 renewal。
- lease 小於等於 30 秒時每 tick renewal。
- process 停頓至 lease 過期後，UPDATE fence 失敗並進入既有 recovery。

### Skipped accounting

- renewal event 消耗累積 skips 後歸零。
- 短任務未再次 renewal 時，finish/failure event 收到剩餘 skips。
- finish 不得重複計入已由 heartbeat event 消耗的 skips。

### Telemetry、CLI 與 retention

- 一次 append 產生恰好一行有效 JSON。
- telemetry 寫檔失敗不改變任務成功或失敗結果。
- CLI 只開啟查詢範圍所需的每日檔案並逐行解析。
- 每個 group 的 count、p50、p95、p99、lock、retry、exhausted、skipped 正確。
- malformed line 計入 `invalid_lines`，不終止 summary。
- retention 只刪除超過 7 天、固定命名、位於 log root 的 regular files；symlink 與其他檔案保留。

### Regression

現有 pack-job fence、cancel、finish、recovery 與 SQLite contention 測試必須維持。Phase 1 不得改變 candidate selection、callback delivery、artifact terminal atomicity 或 recovery decision。

## 驗收與下一階段判斷

部署後先執行 focused load test，再收集至少半天資料。CLI 必須能回答：

- 哪個 action/variant 佔最多 transaction。
- 哪個 action/variant 有最高 p95/p99 transaction duration。
- contention、retry 與 exhausted 主要集中在哪裡。
- heartbeat renewal write 相較 10 秒一次降低多少。

若 heartbeat writer traffic 顯著降低且 lock exhausted 歸零，維持 SQLite 與現有 retry 保險。若 contention 仍集中於 claim、finish 或 recovery，再針對該 transaction boundary 做 Phase 2。只有在 transaction 瘦身後仍持續高 contention，才評估 single-writer；PostgreSQL 留給多 Control Plane、HA 或持續高 write density。
