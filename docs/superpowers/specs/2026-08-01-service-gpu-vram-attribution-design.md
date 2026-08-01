# Service GPU VRAM Attribution Design

## Goal

Show the current, measured GPU VRAM used by each service in the Dashboard
service table. The value must be attributable to a 3waAIHub container; it is
never an estimate or a share of total GPU memory.

## Scope

1. Reuse the existing one-minute host metric collection. Do not add a cron,
   table, daemon, or frontend dependency.
2. For each supported service runtime, query `nvidia-smi` for compute process
   PID and used memory, then map those PIDs to the service containers from its
   existing Compose project. Sum only matched process memory by `service_key`.
3. Native Linux uses host commands. Windows services use their configured WSL2
   runtime, so both `nvidia-smi` and Docker PID inspection run in the same
   distro and PID namespace.
4. Add compact per-service measured telemetry to the host metric snapshot and
   cluster status payload. The router keeps only validated `service_key`,
   `mode`, `vram_used_mb`, and measurement state; `mode` joins the existing
   child Dashboard service table without changing public API contracts.
5. Add an i18n-aware `實際 VRAM` column to `admin/index.php` for local and
   selected child stations.

## Measurement Rules

- A running service with successful Docker PID inspection and no matched GPU
  process is measured as `0 MB`.
- A stopped service, unsupported runtime, failed query, invalid output, or
  non-matching PID is `尚未取得`; it has no numeric value.
- Only non-negative, bounded integer memory values are accepted. Snapshots do
  not retain PIDs, container IDs, command output, or connection details.
- Windows hosts without a usable WSL2 GPU/Docker PID view remain unknown. They
  must not fall back to Windows-side PID guesses.

## Data Flow

```text
nvidia-smi PID + used memory
  + Docker container PID inspection in the same runtime
  -> host_metric_snapshots.service_gpu
  -> local Dashboard service table
  -> cluster_status compact service_gpu
  -> selected child Dashboard service table
```

## Out of Scope

- Per-service VRAM history, charts, accounting, or estimates.
- GPU processes not attributable to a registered 3waAIHub service.
- Native Windows process attribution outside the configured WSL2 runtime.

## Verification

- Collector tests cover multi-process sums, a measured zero, unmapped PIDs,
  malformed values, query failures, and WSL command routing.
- Cluster tests cover compact relay and rejection of invalid service telemetry.
- Dashboard tests cover local and child table rendering, i18n, and unknown
  values.
- Run the focused control-plane and admin-ui suites.
