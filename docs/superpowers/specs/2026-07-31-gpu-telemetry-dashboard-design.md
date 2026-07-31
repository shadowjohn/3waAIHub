# GPU Telemetry Dashboard Design

## Goal

Make the selected station dashboard show trustworthy GPU telemetry:

- Preserve a child station's current GPU utilization and temperature.
- Show each service's Pack-declared GPU requirement, not an invented share of host VRAM.
- Show 24-hour GPU temperature and VRAM usage charts below the dashboard's service section.

## Data Flow

`cluster_status.php` already returns the latest stored GPU metric. The Router must retain the validated `util_percent` and `temperature_c` fields when it prepares a station dashboard summary. Missing fields from an older station remain unavailable and render as `N/A`.

The local dashboard reads its existing `host_metric_snapshots` rows for the previous 24 hours. The Router stores one compact GPU history row after each successfully validated child status refresh. A row contains only the station id, temperature, used VRAM, total VRAM, and timestamp. Rows older than 24 hours are deleted as new rows are saved. The history is not exposed through a public API.

## Service GPU Requirement

The service table gains a `GPU 需求` column. Its value is static Pack metadata:

- For an asynchronous Pack job, use the runner's `required_vram_mb`.
- Otherwise use the Pack's declared minimum VRAM requirement.
- Missing metadata displays as `—`.

The requirement is transmitted in authenticated cluster status data for published modes so the Router can render child station services. It does not claim to be current per-process VRAM usage.

## Dashboard

Below the existing service status section, render two local Chart.js line charts:

1. `GPU 溫度（24 小時）` in degrees Celsius.
2. `GPU VRAM 使用量（24 小時）` in MB, with total VRAM as a capacity reference when available.

The selected station determines the source: local snapshots for a standalone/local view, Router-owned compact history for a child or self station. Empty history renders an existing-style compact empty state. Existing local assets and the current dashboard visual system are reused.

## Compatibility And Failure Handling

- Existing child stations may omit the new GPU or requirement fields; the Router accepts those payloads and displays unavailable values.
- Invalid telemetry is discarded by the existing status snapshot validator and is never written to history.
- A failed child refresh does not add a history row.
- No per-process names, client data, tokens, or raw status payloads are stored in the history table.

## Verification

- Extend focused Cluster tests for compact GPU telemetry, requirement transport, history retention, and old-payload compatibility.
- Extend dashboard tests for summary forwarding, GPU-requirement labels, and chart data.
- Run PHP lint for changed files, the focused test suites, and a dashboard screenshot check.

## Scope Boundary

Actual per-service VRAM attribution is intentionally excluded. It requires a reliable process-to-service mapping and will be added only when that mapping is available.
