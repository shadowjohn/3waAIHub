# Cluster GPU History Design

## Goal

Show trustworthy GPU telemetry for the selected Dashboard station: current GPU
utilization and temperature, plus 24-hour charts for temperature and used VRAM.
Both the local Hub and paired child stations must work.

## Scope

1. Keep using the local one-minute `host_metric_snapshots` collector. Do not
   add another local collector or external monitoring dependency.
2. On each successful child station refresh, save a compact GPU history sample
   for that station. Store only timestamp, station id, GPU availability,
   utilization, used/total VRAM, and temperature.
3. Keep 24 hours of child samples through the existing retention job.
4. Query the selected station's local or child samples and render two existing
   Chart.js line charts: GPU temperature and used VRAM.
5. Keep the current Dashboard cards and make their values use the collected
   `util_percent` and `temperature_c` fields when present.

## Data Flow

```text
nvidia-smi -> host_metric_snapshots -> local Dashboard history
child cluster_status -> successful cluster refresh -> child GPU history -> selected child Dashboard history
```

Missing or unavailable GPU fields are omitted from a sample. The Dashboard
renders no misleading zero-value points and shows its existing N/A state.

## Out of Scope

Per-service VRAM is not included. Mapping `nvidia-smi` process IDs through
Docker reliably is separate work; a heuristic would make the Dashboard less
trustworthy.

## Verification

- Unit coverage: compact child history accepts valid bounded GPU telemetry and
  rejects malformed values; local and child history queries return only the
  last 24 hours.
- Dashboard coverage: chart data exposes both series when snapshots exist and
  preserves N/A when they do not.
- Run the control-plane suite and the admin-ui suite.
