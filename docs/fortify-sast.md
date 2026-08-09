# Fortify SAST 掃描範圍

本專案使用 `scripts/fortify_sast.ps1` 執行可重現的 production SAST 掃描。它只翻譯可部署的一方程式碼，刻意排除測試 fixture、歷史 worktree、執行資料與第三方套件。

```powershell
Set-Location -LiteralPath 'D:\DATA\3waAIHub'
.\scripts\fortify_sast.ps1 -Check
.\scripts\fortify_sast.ps1 -BuildId '3waAIHub-20260809' -OutputPath 'D:\DATA\3waAIHub\data\fortify\3waAIHub-20260809.fpr'
```

掃描完成會輸出 FPR 的 SHA-256。將 FPR 匯入 Audit Workbench 前，應先確認這個雜湊值與產物相符。

## 範圍

- 包含：根目錄 PHP、`app/`、`admin/`、`catalog_show/`、`scripts/`、`i18n/`、`packs/`、部署 PowerShell、專案 JavaScript 與 `bin/aihub-run`。
- 排除：`.git/`、`.worktrees/`、所有 root／Pack 內嵌的 `tests/`、`data/`、`docs/`、`tools/`、vendor、node_modules、Python virtual environment、快取與已打包第三方 jQuery。

這不是 suppression 機制。production finding 仍應依來源、sink、可到達性與既有防護逐筆判定；只有確認為測試 fixture、歷史 checkout 或第三方程式碼，才應在範圍層排除。

## 驗收

腳本在 scan 前會列出 Fortify 實際翻譯的檔案；只要偵測到 `.worktrees/`、`tests/` 或 `data/`，就直接失敗，不產生可被誤解的報告。

遠端 FoD／SSC 匯入另依組織既有 release 流程處理；本腳本只負責建立可追溯的本機 FPR。
