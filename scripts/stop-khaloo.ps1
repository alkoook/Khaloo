$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$artisanPath = Join-Path $projectRoot 'artisan'
$runtimePath = Join-Path $projectRoot 'storage\app\runtime'
$mutex = New-Object System.Threading.Mutex($false, 'Local\KhalooWindowsLauncher')

function Stop-TrackedProcess([string] $filename, [string] $kind) {
    $pidPath = Join-Path $runtimePath $filename
    if (-not (Test-Path -LiteralPath $pidPath)) { return }
    $savedPid = 0
    $raw = Get-Content -LiteralPath $pidPath -Raw
    if (-not [int]::TryParse($raw.Trim(), [ref] $savedPid) -or $savedPid -le 0) {
        Remove-Item -LiteralPath $pidPath -Force
        return
    }
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $savedPid" -ErrorAction SilentlyContinue
    $pattern = 'artisan"?\s+' + [regex]::Escape($kind) + '(\s|$)'
    if ($process -and $process.Name -eq 'php.exe' -and $process.CommandLine -match [regex]::Escape($artisanPath) -and $process.CommandLine -match $pattern) {
        if ($kind -eq 'serve') {
            $allProcesses = @(Get-CimInstance Win32_Process)
            $descendants = New-Object System.Collections.ArrayList
            $parents = @($savedPid)
            while ($parents.Count -gt 0) {
                $next = @($allProcesses | Where-Object { $parents -contains $_.ParentProcessId })
                foreach ($child in $next) { [void] $descendants.Add($child) }
                $parents = @($next | ForEach-Object { $_.ProcessId })
            }
            for ($index = $descendants.Count - 1; $index -ge 0; $index--) {
                $child = $descendants[$index]
                if ($child.CommandLine -match [regex]::Escape($projectRoot) -and $child.CommandLine -match 'php(?:\.exe)?\s+-S\s+(?:127\.0\.0\.1|0\.0\.0\.0):8000') {
                    Stop-Process -Id $child.ProcessId -Force -ErrorAction SilentlyContinue
                }
            }
        }
        Stop-Process -Id $savedPid -Force -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $pidPath -Force
}

try {
    if (-not $mutex.WaitOne(30000)) { exit 1 }
    Stop-TrackedProcess 'queue.pid' 'queue:work'
    Stop-TrackedProcess 'server.pid' 'serve'
} finally {
    if ($mutex) {
        try { $mutex.ReleaseMutex() } catch {}
        $mutex.Dispose()
    }
}
