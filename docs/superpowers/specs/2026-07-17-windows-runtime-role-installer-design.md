# Windows Runtime Role Installer Design

## Goal

將 Windows 的 `install.ps1` / `uninstall.ps1` 定義為 3waAIHub 主機角色配置器。Windows 可同時是 PHP/SQLite control plane 與 WSL2 Linux Docker runtime 的宿主；Linux Pack 的實際執行環境仍是 WSL2 內的 Linux，不是 Windows native process。

## Current Host Assessment

- Control plane root：`D:\DATA\3waAIHub`
- PHP / SQLite / 必要 extension 已就緒，未產生 `.3waaihub.bak`。
- `data/3waaihub.sqlite` 與初始 `data/services/hello-main` 已存在。
- IIS WebAdministration module 未安裝。
- 沒有 Ubuntu WSL distro，也沒有既有 runtime profile。
- Docker Desktop Linux engine 與 NVIDIA runtime 可見，但這不等於有可用的 WSL runtime distro。
- 顯示卡為 GTX 1080 8GB；依目前 Pack contract，YOLO 要 compute capability 8.0，SAM3 要 16GB VRAM 與 compute capability 8.0，因此真 GPU inference 必須被 preflight 擋下。

結論：不必先執行 uninstall。現有 Core 資料保留，後續安裝採 forward migration。

## Canonical Runtime Model

`windows-wsl2-linux-docker` 是 canonical runtime target。Windows 11、Windows Server、WSL distro 名稱與 support level 是 host profile metadata，不是 Pack manifest 的重複 target key。

Pack 仍宣告其原生能力，例如 `linux-docker`。Runtime resolver 以 runtime profile 的 `provides` 將 Pack 的 `linux-docker` requirement 對應到 `windows-wsl2-linux-docker` adapter；不需要為每一個既有 Linux Pack 新增 Windows-specific manifest entry。

```text
Windows PHP / SQLite / IIS control plane
        |
        +-- Windows command adapter: wsl.exe -d <distro> -- ...
                |
                +-- Ubuntu WSL2 + Docker Engine + NVIDIA runtime
                        |
                        +-- /DATA/3waAIHub-runtime  (services/jobs/cache)
                        +-- /DATA/models            (models)
```

`D:\DATA\3waAIHub` 是 control plane source root。高頻 Docker、job workspace、cache、SQLite runtime 與模型不得落在 `/mnt/d/DATA/...`；WSL runtime 使用 ext4 `/DATA`。WSL runtime source mirror / release sync 需記錄 source revision，讓 Linux runtime 與 Windows control plane 的 Pack version 可追溯。

## Installer Modes

| Mode | Normal mode | `-Check` |
| --- | --- | --- |
| `Core` | 設定 PHP、SQLite、data、DB、NTFS ACL；若 IIS 已存在且以系統管理員執行，建立受管理的 App Pool / Site。 | 純讀取檢查 Windows version、PHP CLI/FastCGI、SQLite、ACL、IIS 與 Core smoke。 |
| `WslRuntime` | 後續階段才啟用：建立 WSL ext4 `/DATA`、runtime source mirror、Docker Engine / Compose 與 runtime profile。必須先通過 `-Check`。 | 純讀取檢查 WSL2、指定 distro、Docker Engine / Compose、`nvidia-smi`、container GPU probe 與 ext4 paths，輸出精確修正命令。 |
| `NativeAgent` | 安裝 Windows Native Agent；不安裝 Linux Docker/GPU runtime。 | 檢查 Agent binary、service 與 profile。 |
| `RemoteControlPlane` | 寫入遠端 Linux Station connection profile；不安裝本機 Docker runtime。 | 檢查 profile、DNS/TCP/auth readiness，不執行遠端 job。 |

Windows 11 的推薦順序是 `Core` 後 `WslRuntime`。Windows Server 預設是 `Core`；`WslRuntime` 是 Preview，Docker Desktop 不得在 Windows Server 上安裝或建議，需使用 WSL distro 內 Docker Engine 或 Linux VM。`RemoteControlPlane` 是 Windows Server 的推薦 GPU production 路徑。

`Core` 不檢查、不安裝、也不要求 Docker Desktop。只有 Windows 11 的 `WslRuntime` 可偵測既有 Docker Desktop WSL2 backend；若不存在，後續的 runtime installer 才能明確選擇在 distro 內安裝 Docker Engine。第一版兩者都只報 readiness 與修正命令。

第一版只實作 `WslRuntime -Check`，不自動啟用 Windows Features、不下載顯示卡驅動、不安裝或重建整個 WSL 環境。

## Invocation Contract

所有 mode 都接受 `-Mode`、`-InstallRoot` 與 `-Check`。`WslRuntime` 另接受 `-ModelsRoot`、`-WslDistro`、`-LinuxDataRoot`；預設值必須顯示在 `-Check` 報告中，而不是隱含套用。第一版的 WSL 檢查入口固定可直接使用：

```powershell
.\install.ps1 -Mode WslRuntime -InstallRoot "D:\DATA\3waAIHub" -ModelsRoot "D:\DATA\models" -WslDistro "Ubuntu-24.04" -LinuxDataRoot "/DATA" -Check
```

未知 mode、未知參數組合或 `-RemoveModels` 未連同 `-RemoveRuntimeData` 的解除安裝呼叫，必須在任何變更前以非零 exit code 結束。

## Installer Layout

```text
install.ps1                 # 僅參數驗證、Mode dispatch、統一 exit code
uninstall.ps1               # 僅參數驗證、Mode dispatch、刪除計畫確認
scripts/windows/
  check-core.ps1
  install-iis-php.ps1
  check-wsl-runtime.ps1
  install-wsl-runtime.ps1
  test-wsl-gpu.ps1
  install-agent.ps1
  write-runtime-profile.ps1
```

所有 `-Check` path 必須是零變更：不建立 `data/`、不改 `php.ini`、不寫 runtime profile、不中止或啟動 Docker、也不改 WSL / Windows Features。

## Runtime Profile

正常安裝完成才寫入 `data/runtime_profile.json`。此檔案是 host-local runtime metadata，不進 Git；`-Check` 只輸出候選 profile。

```json
{
  "schema_version": "0.1",
  "host_platform": "windows",
  "host_role": "windows-11-workstation",
  "control_plane": {
    "supported": true,
    "root": "D:\\DATA\\3waAIHub"
  },
  "runtime_targets": {
    "windows-native": {
      "supported": false,
      "reason": "Windows Agent not installed"
    },
    "windows-wsl2-linux-docker": {
      "supported": true,
      "support_level": "production",
      "distro": "Ubuntu-24.04",
      "data_root": "/DATA",
      "runtime_root": "/DATA/3waAIHub-runtime",
      "models_root": "/DATA/models",
      "provides": ["linux-docker"]
    },
    "linux-docker": {
      "supported": false,
      "reason": "Direct Linux host target unavailable"
    },
    "remote-linux-agent": {
      "supported": false,
      "reason": "No remote station configured"
    }
  }
}
```

`windows-wsl2-linux-docker.supported` 只有在指定 distro 為 WSL2、Docker / Compose 可執行、container GPU probe 通過且 `/DATA` 位於 distro ext4 時才可設為 `true`。GPU probe 失敗時保留 control plane，但 target 必須是 `false` 並保存可讀原因。

## Runtime Dispatch and Errors

Windows host 上要求 `linux-docker` 的 Pack 時，resolver 先尋找 profile 中提供 `linux-docker` 的 supported adapter；若找到 WSL adapter，所有 service build/start、Local Job 與 GPU probe 都透過 `wsl.exe -d <distro> --` 執行。

沒有可用 adapter 時，command worker、service start/build、`aihub-run` 與 Local Job 必須在任何 Docker/Bash 呼叫前回覆：

```text
unsupported: linux-docker target is not available on Windows host
```

這是 runtime target 未就緒的結果，不是 Windows native fallback。不得偷偷使用 `/mnt/d` workspace、CPU fallback 或 Windows Docker CLI 來偽裝 Linux Pack runtime。

## Smoke and Acceptance Sequence

1. `install.ps1 -Mode Core -Check` 通過；Core 正常安裝後可由 IIS / PHP built-in server 開啟登入頁。
2. `install.ps1 -Mode WslRuntime -Check -InstallRoot "D:\DATA\3waAIHub" -ModelsRoot "D:\DATA\models" -WslDistro "Ubuntu-24.04" -LinuxDataRoot "/DATA"` 只報 readiness 與修正指令。
3. WSL runtime 安裝完成後，`test-wsl-gpu.ps1` 以 NVIDIA CUDA sample container 驗證 GPU visibility。
4. `hello` Pack 跑真 Linux Docker service。
5. YOLO / SAM3 先跑 manifest / gateway mock hello；真 inference 必須再通過 Pack hardware preflight。此主機的 GTX 1080 8GB 預期被 YOLO / SAM3 的現有 contract 擋下。
6. Linux CI 驗證 native Linux；Windows CI 驗證 Core、runtime profile resolver 與 WslRuntime `-Check` fixture。Windows runtime test 不得依賴真 GPU。

## Uninstall Contract

`uninstall.ps1` 必須使用同一個 `-Mode` 與 `-Check` 介面：

```powershell
.\uninstall.ps1 -Mode Core -Check
.\uninstall.ps1 -Mode WslRuntime -Check
.\uninstall.ps1 -Mode NativeAgent -Check
.\uninstall.ps1 -Mode RemoteControlPlane -Check
```

預設 uninstall 只移除由 3waAIHub 明確標記管理的 role configuration：runtime profile、受管理 IIS Site/App Pool、Agent service 或 remote connection profile。它不得刪除全域 PHP、Docker Desktop、WSL feature、WSL distro、NVIDIA driver、`D:\DATA\3waAIHub`、SQLite DB、models 或 `/DATA`。

移除 WSL runtime generated data 需要明確 `-RemoveRuntimeData`；移除 `/DATA/models` 另需獨立 `-RemoveModels` 加互動確認。`-Check` 必須列出精確目標、容量與保留項，零變更。IIS removal 只可對 profile 中 `managed_by=3waAIHub` 的 Site/App Pool 執行。

## Out of Scope for First Implementation Plan

- 自動安裝 WSL、Windows optional features、GPU driver 或 Docker Engine。
- Windows Server Docker Desktop。
- Windows Native Agent 實作。
- Remote Linux Agent protocol。
- 放寬 YOLO / SAM3 的 GPU contract 或加入未宣告的 CPU fallback。
