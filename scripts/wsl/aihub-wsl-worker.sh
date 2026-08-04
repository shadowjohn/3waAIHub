#!/usr/bin/env bash
set -u

fail() {
    printf '%s\n' "aihub-wsl-worker: $*" >&2
    exit 64
}

windows_php=${AIHUB_WINDOWS_PHP:-}
windows_hub_root=${AIHUB_WINDOWS_HUB_ROOT:-}
[ -n "$windows_php" ] && [ -x "$windows_php" ] || fail 'AIHUB_WINDOWS_PHP is unavailable.'
[ -n "$windows_hub_root" ] && [ -d "$windows_hub_root" ] || fail 'AIHUB_WINDOWS_HUB_ROOT is unavailable.'

task_worker="$windows_hub_root/scripts/task_worker.php"
command_worker="$windows_hub_root/scripts/command_worker.php"
[ -f "$task_worker" ] && [ -f "$command_worker" ] || fail 'Windows worker scripts are unavailable.'

# systemd services start outside an interactive Windows cwd; /mnt/c prevents the
# Windows interop layer from falling back to an unsupported WSL UNC cwd.
cd /mnt/c || fail 'Windows mount is unavailable.'
windows_task_worker=$(wslpath -w "$task_worker")
windows_command_worker=$(wslpath -w "$command_worker")
trap 'exit 0' INT TERM

tick=0
while true; do
    "$windows_php" "$windows_task_worker" --limit=1 --runtime=wsl || printf '%s\n' 'aihub-wsl-worker: task_worker failed; retrying.' >&2
    tick=$((tick + 1))
    if [ $((tick % 10)) -eq 0 ]; then
        "$windows_php" "$windows_command_worker" --limit=1 --runtime=wsl || printf '%s\n' 'aihub-wsl-worker: command_worker failed; retrying.' >&2
    fi
    sleep 0.5
done
