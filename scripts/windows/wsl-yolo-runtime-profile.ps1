function Get-WslYoloRuntimeProfile {
    param(
        [Parameter(Mandatory = $true)]
        [string]$InstallRoot,
        [Parameter(Mandatory = $true)]
        [string]$GpuName
    )

    $manifestPath = Join-Path $InstallRoot 'packs\yolo\pack.json'
    if (-not (Test-Path -LiteralPath $manifestPath)) {
        throw "YOLO Pack manifest is missing: $manifestPath"
    }
    $profiles = (Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json).wsl_runtime_profiles
    if ($null -eq $profiles -or $null -eq $profiles.default) {
        throw 'YOLO WSL runtime profiles are missing a default profile.'
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
