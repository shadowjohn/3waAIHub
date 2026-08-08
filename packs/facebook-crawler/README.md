# Facebook Crawler HubPack

`facebook-crawler` 是 CPU 型背景 Pack，用 Playwright 讀取 Facebook 粉絲專頁、社團或其他公開 feed 的近期文章，輸出受 Hub 管理的 JSONL Dataset。每次手動 run 可登記 1 至 30 個目標，每個目標抓取最近 10 至 30 筆；它刻意不做無限歷史捲動。

## 執行平台

- Linux：使用本機 Linux Docker worker。
- Windows WSL：使用 Pack 宣告的 WSL2 Linux Docker runtime；只同步固定 request 與 artifact，不把瀏覽器 Profile 複製回 Windows 工作目錄。
- 不需要 GPU，runner 的 accelerator 固定為 `cpu`。
- 每台 Hub 都能獨立安裝，但登入 Profile 與 Cookie 只屬於建立它的本機節點。

## 登入 Profile

公開粉絲專頁或不需登入的公開來源可省略 `profile_id`。需要帳號權限時，先以 `facebook_profile_start` 建立短效登入工作階段；可選瀏覽器手動登入，或在 HTTPS 下送入一次性的帳號密碼。帳密只送往當次 login broker，不寫入資料庫、session、log 或設定檔。

第一次登入遇到 2FA 或 CAPTCHA 時，必須由使用者在短效登入頁手動完成。系統不提供 CAPTCHA solver，也不自動加入社團。私密社團只有在該 Facebook 帳號本來已加入且頁面可正常瀏覽時才可能取得資料；抓不到時該目標會回報失敗或空結果。

Profile 的 browser state 存在 Hub data root 下的私有目錄，目錄權限為 `0700`、檔案為 `0600`。一次只有一個 task 能持有同一 Profile；刪除 Profile 會先確認沒有登入 broker 或 crawler task 正在使用。

## API 工作流

1. 視需要呼叫 `facebook_profile_start`，在短效頁完成登入。
2. 呼叫 `facebook_crawl`，送入可選的 `profile_id`、`targets` 與 `limit_per_target`。
3. 以回傳的 `task_status`、`task_result` 與 `task_log` 連結追蹤背景工作。
4. 用 `facebook_run_last` 取得最近一次 terminal run；用 `facebook_dataset_items` 依 `offset`／`limit` 預覽 JSONL；或由 `artifact` 下載完整檔案。

Dataset 與 artifact 依 API member 隔離，預設保留 30 天。網頁測試中心不保存 Bearer token 或 Facebook 密碼。

## 排程與 Cluster 邊界

Phase A 只支援手動啟動背景 run。若現有 `nchc_ai` 需要定期更新，可由它在外部排程呼叫同一個 API；Pack 本身暫不新增常駐排程器。

`facebook_crawl` 目前不發布到 Cluster catalog，因為 Profile 是節點私有狀態。Phase B 才評估 node-pinned Router：Router 必須把後續 Profile 與 Dataset 操作固定導回同一節點，確認生命週期完整後才能開放。

目前不做自動入社、CAPTCHA 破解、深度歷史爬取、caller 自訂 proxy 或跨節點複製 Cookie。

## 真實 Smoke

先在 API 金鑰允許 `facebook_crawl`，並把 token 放在 web root 外的權限受控檔案：

```bash
php scripts/facebook_crawler_smoke.php \
  --api-base=https://3wa.tw/3waAIHub/api.php \
  --token-file=/path/outside/webroot/facebook-crawler.token \
  --profile-id=fbp_<opaque> \
  --target=https://www.facebook.com/<approved-official-page>
```

公開頁 smoke 可省略 `--profile-id`。正式驗收應確認 task 成功、JSONL artifact 可下載、`facebook_dataset_items` 可分頁，且 response 與 log 不含帳密、Cookie、主機路徑或 broker 內部資訊。
