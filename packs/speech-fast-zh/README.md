# Fast Chinese Draft ASR

`speech-fast-zh` 是獨立的 CPU 中文快速辨識 Pack，適合先取得仿聲參考音檔的草稿逐字稿；精準時間軸、說話者分離與較高辨識品質仍使用 `whisper-asr`。

## API

非同步 Mode 是 `speech_transcribe_fast_zh`。上傳 WAV 音檔或提供既有的
`audio`、`cleaned_audio`、`vocals_audio` artifact；`include_draft_subtitles=true`
時會額外產出粗略時間軸的 `draft_subtitle_srt` 與 `draft_segments`。Paraformer
若沒有回傳 token timestamps，但已有文字結果，會產出覆蓋整段音檔的一條粗字幕，並在
`transcription_report.warnings` 記錄 `token_timestamps_unavailable`；它不是逐字對齊字幕。

輸出的 `transcript_json` 同時保留：

- `raw_text`：Paraformer 原始輸出，沒有被改寫。
- `text`：供人閱讀的台灣繁體草稿。只做 OpenCC `s2twp`、`賬→帳`、全半形、被拆開的英文字母，以及保守的 `樂色／勒色→垃圾` 修正。

它不會自行刪除口頭禪、合併重複詞、補標點、加 emoji 或確認仿聲 profile 的逐字稿。

## 模型預置

先建立 runner image，再由管理者明確執行一次模型預置：

```bash
AIHUB_MODELS_DIR=/absolute/path/to/models \
  bash packs/speech-fast-zh/jobs/provision_offline_models.sh
```

模型會放在 `models/speech-fast-zh/paraformer-zh-small-2024-03-09`。推論容器只讀取已驗證的模型掛載，執行時使用隔離網路且不需要 GPU。

Pack 已通過 `L4-real-inference`：image 與已驗證模型在 Docker 隔離網路中，以 Hub 的通用
async task workspace 完成真實 CPU 推論與產物發布。

## Runtime

目前僅支援 Linux Docker。Windows WSL2 的 generic async task transport 尚未提供給這個 Pack，因此不宣告 WSL target。

## 驗收

`service/inference_smoke.py` 是 L4 驗收：它會使用既有的中文 WAV fixture 做真實 CPU 推論，驗證非空文字、RTF 與選用粗字幕產物；它檢查服務活著與產物正確，不把單一短音檔當成語音辨識品質基準。

在 3wa 的 Linux Docker 主機上，6 秒的既有中文 WAV fixture 以通用 task workspace 實測完成約 3.42 秒（RTF 0.57），並產出 transcript、report、draft segments 和 SRT。這是當時主機負載下的驗收紀錄，不是效能承諾。

## L5 API 與測試中心

Pack 的 manifest 已提供 `L5-benchmark-ready` contract：公開 API 文件會列出 `file`、
`include_draft_subtitles`、非同步 task follow-up，以及四種 result artifact。測試中心的
`speech_fast_zh_submit_audio` 驗證可提交的 API contract；它不會把模型品質誤報為 benchmark 成功。

完成本機真實 API 驗收後，以下指令會下載並校驗所有 artifact，再把
`speech_fast_zh_async_complete` 寫入測試中心。`--record-l5` 刻意只接受 loopback API，避免遠端
站台的結果被誤登記成本機 L5：

```bash
php scripts/audio_packs_acceptance.php \
  --base-url=http://127.0.0.1/3waAIHub/api.php \
  --token='<TOKEN>' \
  --pack=speech-fast-zh \
  --fixture=packs/llm-gemma4-12b/demo/audio_zh_smoke.wav \
  --record-l5 \
  --json
```

此驗收為 CPU-only，不要求 `nvidia-smi` 或 GPU；驗證 transcript、report、粗 SRT、draft segments、
artifact hash/size 與下載權限。L5 紀錄仍代表此主機當次真實 API 成功，不代表所有音檔的辨識品質。
