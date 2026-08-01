# Edge TTS 外部語音服務

`edge_tts` 是 Microsoft Edge 線上語音的 experimental Pack，只供非機密文字使用。
它是 CPU-only；GPU 不會使用，也沒有 voice clone、GPU lease 或供應商 secret。
管理員必須先安裝並啟用 service，並為呼叫端 token 授權 `edge_tts` mode。
容器只可連線至 `speech.platform.bing.com:443`；供應商或網路錯誤會結束 task，
不會改用其他 provider。

API endpoint 是 Hub 的 `api.php`，Cluster 則是 `cluster_api.php`。下列
`<API_ENDPOINT>` 例如 `https://hub.example/3waAIHub/api.php`；token 與 host 都是
placeholder，請勿把真 token 寫入程式、commit、log 或 shell history。

## 聲線清單與試聽

認證後的 GET `?mode=edge_tts` 回傳可用聲線清單：

```bash
curl --fail --silent --show-error \
  -H 'Authorization: Bearer <TOKEN>' \
  '<API_ENDPOINT>?mode=edge_tts'
```

每個 list row 固定包含 `id`、`display_name`、`locale`、`gender`、`memo`、
`demo_text`、`demo_url`。`demo_url` 是同一 endpoint 的相對認證 URL；以
`GET <API_ENDPOINT><demo_url>` 可串流 `audio/mpeg` 的試聽檔，不會洩漏 storage
路徑。只可使用 list 回傳的相對 URL 接到同一 API endpoint，不能把它替換為任意 URL；
試聽也必須帶同一個 token：

```bash
# 範例為 list 回傳的相對 demo_url：?mode=edge_tts&voice=zh-TW-HsiaoChenNeural
curl --fail --silent --show-error \
  -H 'Authorization: Bearer <TOKEN>' \
  '<API_ENDPOINT>?mode=edge_tts&voice=zh-TW-HsiaoChenNeural'
```

demo 回應帶 `Cache-Control: private, no-store`，不應由共享快取保存。`memo` 是靜態
catalogue 的描述性中繼資料，不是 Edge 上游的聲線風格或韻律控制。

Demo 於每次安裝時重新生成並檢查檔案、大小與 hash。只有 demo 成功且驗證通過的
聲線才會出現在 GET list；可部分成功並只發布成功者，但所有生成皆失敗時 install 會失敗。
MP3 不會 commit 成 Pack static asset；驗證通過的 staging 目錄才會
atomically publish 到
`HUB_DATA_DIR/results/edge-tts-demos/<service-key>/current`。靜態 canonical source
為 [`service/voice_catalog.json`](service/voice_catalog.json)。

## 容器出口防護

此 Pack 使用 container-local、fail-closed 的 egress firewall。只有受信任的
`entrypoint` 初始化期間擁有 `NET_ADMIN`；規則驗證完成後會移除 capability，再以
non-root `edge` 使用者執行合成或 demo generator。此設計不修改 host firewall、
Docker daemon 或 Docker network。

## Windows WSL Runtime

Windows 只會透過 Pack 明確宣告的 `windows-wsl2-linux-docker` job target 執行 Edge
TTS；它是 CPU-only 的 `internal_task`，不會將 Windows 的直接 `linux-docker` target
自動改送 WSL。Marketplace **Build** 只建立／驗證 WSL image，**Start** 才生成已驗證
demo voices 並開啟 Hub admission，**Stop** 只關閉 admission，不會保留常駐容器。

WSL Docker 是登入使用者的 runtime。Windows 的 LocalSystem 排程可安全消化
control-plane `command_worker.php`，但不能保證可使用該使用者的 WSL distro；Edge task
必須由持有 WSL runtime 的帳號執行 `scripts/task_worker.php`，或改由 Remote Linux Agent
執行。

| ID | 語系 | 聲線 | 性別 | memo |
| --- | --- | --- | --- | --- |
| `zh-TW-HsiaoChenNeural` | zh-TW | 小晴 | 女 | 清亮，適合主持與旁白。 |
| `zh-TW-HsiaoYuNeural` | zh-TW | 阿岑 | 女 | 柔和，適合聊天與故事角色。 |
| `zh-TW-YunJheNeural` | zh-TW | 阿哲 | 男 | 穩定，適合解說與來賓。 |
| `zh-CN-XiaoxiaoNeural` | zh-CN | 曉曉 | 女 | 明亮，適合正式女聲旁白。 |
| `zh-CN-XiaoyiNeural` | zh-CN | 小藝 | 女 | 輕柔，適合故事與生活感對談。 |
| `zh-CN-YunjianNeural` | zh-CN | 雲健 | 男 | 厚實，適合劇情男聲。 |
| `zh-CN-YunxiNeural` | zh-CN | 雲希 | 男 | 年輕，適合輕鬆聊天。 |
| `zh-CN-YunxiaNeural` | zh-CN | 雲夏 | 男 | 少年感，適合年輕角色。 |
| `zh-CN-YunyangNeural` | zh-CN | 雲揚 | 男 | 播報感，適合公告與資訊整理。 |
| `zh-CN-liaoning-XiaobeiNeural` | zh-CN-liaoning | 小北 | 女 | 東北口音，適合特色角色。 |
| `zh-CN-shaanxi-XiaoniNeural` | zh-CN-shaanxi | 小妮 | 女 | 陝西口音，適合地方感角色。 |
| `zh-HK-HiuGaaiNeural` | zh-HK | 嘉嘉 | 女 | 粵語女聲，爽朗清楚。 |
| `zh-HK-HiuMaanNeural` | zh-HK | 漫漫 | 女 | 粵語女聲，柔和自然。 |
| `zh-HK-WanLungNeural` | zh-HK | 阿龍 | 男 | 粵語男聲，穩重有厚度。 |

## 非同步 L5 合成

`POST /api.php?mode=edge_tts` 使用 `application/x-www-form-urlencoded`。指定
`include_subtitles=true` 會要求完整 L5 artifact set：`generated_audio`（MP3）、
`synthesis_metadata`、`subtitle_vtt`（`subtitle.vtt`）、`subtitle_srt`
（`subtitle.srt`）、`speech_timeline`（`speech_timeline.json`）。字幕與 timeline
包含送出的文字，應以正常 artifact retention 與存取控管處理。

標準 MIME 是 `generated_audio` 的 `audio/mpeg`、`synthesis_metadata` 與
`speech_timeline` 的 `application/json`、`subtitle_vtt` 的 `text/vtt`、以及
`subtitle_srt` 的 `application/x-subrip`。驗收也接受 `subtitle_vtt` 的 `text/plain`，
以及 `subtitle_srt` 的 `text/plain`、`text/x-subrip`、`text/srt`；這些是相容值，
不是額外 artifact。

```bash
curl --fail --silent --show-error --request POST \
  -H 'Authorization: Bearer <TOKEN>' \
  --data-urlencode 'text=這是非機密的短句。' \
  --data-urlencode 'voice=zh-TW-HsiaoChenNeural' \
  --data 'include_subtitles=true' \
  '<API_ENDPOINT>?mode=edge_tts'
```

提交回應提供 task URL。client 先以 `task_status` 輪詢，成功後讀取
`task_result`，再以 `artifact` 下載五個 owned artifacts，最後對每個 artifact
呼叫 `task_artifacts_ack`。範例請見
[`docs/api_examples.md`](../../docs/api_examples.md#edge-tts-聲線清單與非同步合成)。

## L5 readiness 與真實驗收

`runtime_level` / `target_level` 是 `L5-benchmark-ready`，但 readiness 只有在
service `installed`、`enabled`、`running`，且 `edge_tts_async_complete` 有最新 PASS
的真實 external acceptance record 時才會完整。一般
`php scripts/benchmark.php --pack=edge-tts --case=edge_tts_async_complete` 會以
`external_acceptance_requires_script` fail closed，且在任何 network request 或 task
建立前停止；它不是驗收執行器。

`admin/pack_readiness.php` 只檢查 L5 contract 與已保存的 benchmark，不能單獨證明
service 已 `installed/enabled/running`；實際 service 狀態請另在 `admin/packs.php`
確認。

由管理者明確執行 [`scripts/edge_tts_acceptance.php`](../../scripts/edge_tts_acceptance.php)
才會做真實驗收。安全操作、五個 failure code、Cluster endpoint 與結果 redaction
請依 [真實 smoke runbook](../../docs/operations/edge-tts-real-smoke.md) 執行。

## 執行期錯誤

合成 runner 的受限錯誤碼為 `upstream_unavailable`、`edge_tts_timeout`、
`edge_tts_failed`、`artifact_write_failed`。真實 acceptance CLI 另有自己的五個
公開驗收錯誤碼，請勿把它們當成一般 client synthesis error。
