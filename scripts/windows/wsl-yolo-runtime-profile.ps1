function Get-WslPackRuntimeProfile {
    param(
        [Parameter(Mandatory = $true)]
        [string]$InstallRoot,
        [Parameter(Mandatory = $true)]
        [string]$PackId,
        [Parameter(Mandatory = $true)]
        [string]$GpuName
    )

    if ($PackId -notmatch '^[a-z][a-z0-9-]{0,63}$') {
        throw 'PackId is invalid.'
    }
    $manifestPath = Join-Path $InstallRoot ('packs\' + $PackId + '\pack.json')
    if (-not (Test-Path -LiteralPath $manifestPath)) {
        throw "WSL Pack manifest is missing: $manifestPath"
    }
    $profiles = (Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json).wsl_runtime_profiles
    if ($null -eq $profiles -or $null -eq $profiles.default) {
        throw "WSL Pack runtime profiles are missing a default profile: $PackId"
    }

    foreach ($property in $profiles.PSObject.Properties) {
        if ($property.Name -eq 'default') { continue }
        $profile = $property.Value
        foreach ($pattern in @($profile.gpu_name_patterns)) {
            if ($pattern -ne '' -and $GpuName.IndexOf([string]$pattern, [System.StringComparison]::OrdinalIgnoreCase) -ge 0) {
                return $profile
            }
        }
    }

    return $profiles.default
}

function Get-WslYoloRuntimeProfile {
    param(
        [Parameter(Mandatory = $true)]
        [string]$InstallRoot,
        [Parameter(Mandatory = $true)]
        [string]$GpuName
    )

    return Get-WslPackRuntimeProfile -InstallRoot $InstallRoot -PackId 'yolo' -GpuName $GpuName
}

function Get-WslWhisperRuntimeProfile {
    param(
        [Parameter(Mandatory = $true)]
        [string]$InstallRoot,
        [Parameter(Mandatory = $true)]
        [string]$GpuName
    )

    return Get-WslPackRuntimeProfile -InstallRoot $InstallRoot -PackId 'whisper-asr' -GpuName $GpuName
}
