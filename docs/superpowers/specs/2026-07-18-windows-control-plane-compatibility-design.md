# Windows Control Plane Compatibility Design

## Goal

完成 Windows-1：讓 3waAIHub 的 Windows PHP／SQLite／Admin UI control plane 在 Windows 11 與 Windows Server 上具備一致、可驗證的行為，Windows CI 全綠；`WslRuntime` 本輪只提供 read-only readiness report，不執行 Linux Pack。

本規格採 control-plane-first。Windows host 沒有已實作的 runtime target 時，Service、Local Job 與 Linux Docker maintenance action 必須在執行 Docker 前明確失敗。Linux 原生 runtime、Pack Dockerfile、compose、shell job、cron 與 GPU 行為不變。

**Docker 是否存在，只是環境資訊，不代表 Pack target 可執行。**

**安全路徑比較使用 canonical comparison；realpath() 失敗絕不等於安全。**

**Windows Core 的不適用能力顯示 N/A，不顯示成系統故障。**

## Approved Scope

### Windows-1：本規格

- Windows Storage Safety
- Shared Command Portability
- Environment Probe Graceful Degradation
- Windows CI
- Local Job／Service exit 78 early gate
- `WslRuntime -Check` readiness report
- TranslateGemma enqueue prerequisite defect fix，以獨立 commit 提交

這些是同一輪實作的工作項，不拆成多個產品 Phase。

### Windows-2：下一份規格

下一刀只做一條 WSL vertical slice：

```text
Windows IIS / PHP
  -> 建立 runtime run
  -> wsl.exe 呼叫 aihub-run
  -> WSL Docker 跑 hello / YOLO predict
  -> 回傳 status / result / logs
```

只支援一個固定 distro、一個固定 Linux runtime root、一個 Pack 與一個 workspace mapping 規則。出現第二種 runtime caller 後才評估抽 adapter；Windows-1 不建立 Shell abstraction、Runtime factory 或完整 WSL mapping layer。

## Platform Names and Roles

UI 與文件使用以下名稱，避免把 3waAIHub role 與 Microsoft Windows Server Core installation option 混淆：

- `3waAIHub Core（Control Plane）`
- `WSL Runtime（Preview）`

Windows 11 可顯示 WSL Runtime readiness。Windows Server 預設為 3waAIHub Core；WSL Runtime 必須標記 Preview，修正提示不得建議或安裝 Docker Desktop。

本輪保留 `hub_platform_target_supported('linux-docker', 'windows')` 的 unsupported 語意。未來 WSL 使用獨立 target `windows-wsl2-linux-docker`，不得因 Windows 上存在 Docker CLI 或 daemon 而把 `linux-docker` 偷改成 supported。

這項決策在 Windows-1 範圍內取代前一份 runtime role installer design 的 `provides: ["linux-docker"]` 自動轉接行為。Windows-2 會以新的 vertical-slice spec 明確定義 Pack 如何選到 `windows-wsl2-linux-docker`；本輪 resolver 不啟用該轉接。

## 1. Storage Path Safety

### Two-level policy

路徑驗證分成兩級，延續現有合法設定：

| Path type | Blocked roots |
| --- | --- |
| 一般 cache／uploads／results／logs | 磁碟根、UNC share root、系統目錄 |
| models root | 上述全部，再額外阻擋 `HUB_ROOT`、`HUB_DATA_DIR` 及其子路徑 |

因此 `HUB_DATA_DIR/cache`、`HUB_DATA_DIR/uploads`、`HUB_DATA_DIR/results`、`HUB_DATA_DIR/logs` 仍合法；models 不得放入 repo 或 runtime SQLite data tree。

### Accepted host path forms

- POSIX absolute path，例如 `/DATA/models`
- Windows drive path，例如 `D:\DATA\models` 或 `D:/DATA/models`
- Windows UNC child path，例如 `\\server\share\3waAIHub\models`

下列路徑必須拒絕：

- `/`
- `D:\`
- `\\server\share`
- traversal 後落入受阻擋 root 的路徑
- 空字串、NUL、相對路徑
- 無法建立可信 canonical comparison path 的輸入

### Canonical comparison

Storage 安全檢查新增專用 canonical comparison helper；不把 `hub_normalize_host_path()` 擴張成萬能 path resolver。

演算法：

1. 驗證 absolute path 類型並拒絕 NUL。
2. 正規化 `/`、`\`、重複 separator、`.` 與 `..`；不得允許 traversal 超出 drive、POSIX 或 UNC share root。
3. 路徑存在時使用 `realpath()`。
4. 路徑不存在時向上尋找最近的既存 ancestor；ancestor 必須能 `realpath()`，再附加已正規化的未建立尾段。
5. 找不到可信 ancestor 或 ancestor `realpath()` 失敗時拒絕。
6. Windows comparison path 統一 separator、drive letter，並採大小寫不敏感比較；`D:\DATA\x` 與 `D:/DATA/x` 視為相同。
7. UNC 的 `\\server\share` 是 share root，不可直接作 Storage root；其 child path 才可進一步驗證。

Artifact、workspace 與 containment check 使用相同 comparison semantics。回傳給檔案 API 的實際 path 保留 native form；comparison path 只用於安全判斷。

## 2. Shared PHP Command Portability

`hub_run_command()` 與其他共用 PHP process entry 繼續使用 `proc_open(command array, ..., env array)`。不重寫既有 runner，也不建立 Shell abstraction。

### Environment merge

有 env override 時，base environment 必須來自 `getenv()` 的全量環境，而不是只依賴可能不完整的 `$_ENV`：

1. 讀取 `getenv()` 全量環境。
2. 合併 explicit overrides。
3. Windows env key 採大小寫不敏感合併，避免同時出現 `PATH` 與 `Path`。
4. 不得遺失 `SystemRoot`、`ComSpec`、`Path`、`TEMP` 等啟動子程序所需環境。

### Shell syntax boundary

共用 PHP command path 不使用：

- Unix inline env，例如 `AIHUB_TEST_DB=... php ...`
- `command -v`
- `2>/dev/null`
- 依賴 Bash quoting 的 command string

Linux-only `.sh` 可繼續使用 Unix syntax；PowerShell 的 `2>$null` 亦不在此限制內。

Token API smoke 改以 command array、env array 啟動 PHP server，並以跨平台 process probe 檢查 `curl`／`curl.exe`。DocParser 的 `pdftoppm` availability 也透過 command array 探測。

API Docs 與 Playground 的人類範例依 control-plane host 顯示：

- Windows：PowerShell `curl.exe` 與 backtick continuation
- Linux：Bash `curl` 與 backslash continuation

## 3. Unsupported Runtime Contract

新增常數：

```php
const HUB_EXIT_UNSUPPORTED = 78;
```

底層 machine contract：

```php
[
    'exit_code' => HUB_EXIT_UNSUPPORTED,
    'error_code' => 'platform_target_unsupported',
    'target' => 'linux-docker',
    'message' => 'linux-docker target is not available on Windows host',
    'retryable' => false,
]
```

為維持現有 command runner caller，完整結果必須同時帶 `stdout=''`，以及已格式化的人類文字 `stderr`／`output`。`message` 與底層 reason 不含 `unsupported:` 前綴；CLI stderr 才格式化為：

```text
unsupported: linux-docker target is not available on Windows host
```

契約分工：

- Machine contract：`error_code=platform_target_unsupported`
- Process contract：exit `78`
- Human contract：固定 message

Command worker、service build/start、Local Jobs 與 Linux Docker maintenance action 使用相同 contract。Unsupported gate 必須早於 compose generation side effect、workspace creation與任何 `docker`／`docker compose` process invocation。

DB 不新增 unsupported state；仍記錄既有 failed 狀態。`runtime_runs.error_code` 使用穩定 error code；`command_jobs` 新增 nullable `error_code` 欄位，使 service／worker failure 可保存 machine contract，而不是把 error code 混入人類訊息。

## 4. Docker Probe and Pack Capability

Environment Probe 可獨立顯示主機觀察值：

- Docker CLI detected
- Docker daemon reachable
- Native `nvidia-smi` GPU／VRAM

這些欄位不得推導 Pack readiness。Pack readiness 只由以下條件決定：

```text
requested platform target
  + configured supported runtime target/profile
```

Windows-1 沒有可執行的 Linux Pack runtime target，因此即使 Docker Desktop 可連線，`linux-docker` 仍為 unsupported。`install.ps1 -Mode WslRuntime` 保持 check-only，不寫 supported runtime profile、不啟動 service、不執行 Local Job。

## 5. Environment Probe Graceful Degradation

Windows 下列 Linux-only能力必須在 probe 起點短路：

- `sys_getloadavg()`
- `/proc/meminfo`
- `vmstat` 與 swap `si`／`so`
- `/etc/cron.d`
- `flock`
- POSIX user／group
- Linux Docker root `/var/lib/docker`
- `/DATA/docker` status
- Linux worker install command

統一 N/A 資料形狀：

```json
{
  "available": false,
  "status": "not_applicable",
  "reason": "not_available_on_windows"
}
```

UI 將 `not_applicable` 顯示為 N/A／不適用，不列為紅色故障，也不產生 Linux修正命令。Windows 原生 `nvidia-smi` 繼續正常採集 GPU、driver 與 VRAM。

本輪不新增 WMI／CIM host memory collector；Windows memory、load、swap 欄位可為 N/A。需要 Dashboard host metrics parity 時再加入獨立原生 collector。

## 6. Tests and CI

### Visible skips

測試 runner 新增明確 skip 機制。Skip 必須輸出名稱與原因，例如：

```text
[SKIP] Photo unlink permission semantics: chmod does not deny deletion on Windows
```

Suite summary 固定包含：

```text
tests=<N> failures=0 skipped=<N>
```

Skip 不可計為 PASS。只有 capability 確實不存在且測試無法可靠重現時才 skip：

- symlink：fixture 建立成功就驗證；權限／Developer Mode 不允許時明確 skip。
- unlink failure：Windows 優先以開啟中的 file handle 製造刪除失敗；若當前 filesystem 無法可靠重現才明確 skip。
- Linux runtime：Windows assert machine contract、exit 78 與固定 human message，不執行 runtime。

### Early-gate proof

Windows unsupported tests 除了驗證 exit `78`，還必須以 injectable command spy 或現有最小 seam 證明沒有呼叫 `docker`／`docker compose`。不引入 mock framework。

### Self-check and existing failures

- Assert-enabled self-check 的 artifact path 比較使用 canonical path，不比較 mixed-separator raw string。
- Token API smoke 移除 Unix inline env 與 `command -v curl`。
- Photo unlink 與 model symlink tests 採 capability-aware 行為。
- TranslateGemma enqueue contract 先以 prerequisite defect fix 獨立 commit 修正，再進行 portability commits。

### CI jobs

保留 Ubuntu job 驗證 native Linux runtime contracts。新增 Windows job驗證：

1. PHP lint
2. Windows installer tests
3. assert-enabled `scripts/self_check.php`
4. control-plane unit suite
5. Linux runtime cases explicit unsupported／skip
6. `WslRuntime -Check` readiness report fixture

Windows CI 不依賴真 GPU、Docker Desktop 或已安裝的 Ubuntu distro。

## 7. WSL Readiness Report

`WslRuntime -Check` 是 read-only report。環境未準備完成時仍 exit `0`，輸出至少包含：

```text
Status: NOT READY
Ready: false
```

腳本 exception、參數錯誤或 probe 本身崩潰才使用非零 exit。後續可新增 `-Json`，讓 CI 讀取穩定欄位而非自由文字；Windows-1 先保留文字輸出並增加固定 `Ready: true|false`。

## Commit Boundaries

1. Prerequisite defect fix：TranslateGemma enqueue contract。
2. Windows portability：storage、command、probe、unsupported contract、tests。
3. Windows CI／docs。

每個 commit 只 stage 自己的檔案／hunk；不得夾帶目前工作區其他未提交變更。

## Acceptance

Windows 11 與 Windows CI 的固定基線：

```powershell
.\install.ps1 -Mode Core -Check
.\tests\test_windows_installer.ps1
php -d zend.assertions=1 -d assert.exception=1 scripts/self_check.php
php scripts/run_tests.php
.\install.ps1 -Mode WslRuntime -Check
```

預期：

- Core check PASS。
- Windows installer tests PASS。
- assert-enabled self-check PASS。
- control-plane unit suite `failures=0`，所有 skip 可見且有原因。
- Unsupported tests 證明沒有呼叫 Docker。
- WSL 未就緒時輸出 `Status: NOT READY` 與 `Ready: false`，exit `0`。
- Linux CI 持續 PASS，Linux runtime 行為未改。

## Out of Scope

- WSL runtime install 或自動啟用 Windows Features。
- Docker Desktop installation 或 Windows Server Docker Desktop guidance。
- `wsl.exe` 執行 Pack。
- Windows path 到 WSL path mapping。
- runtime source sync、workspace sync、model sync。
- Windows Native Agent。
- Remote Linux Agent protocol。
- WMI／CIM host metrics parity。
