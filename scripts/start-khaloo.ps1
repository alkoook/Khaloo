$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$artisanPath = Join-Path $projectRoot 'artisan'
$runtimePath = Join-Path $projectRoot 'storage\app\runtime'
$HostAddress = '0.0.0.0'
$Port = 8010
$url = "http://127.0.0.1:$Port"
$serverPidPath = Join-Path $runtimePath 'server.pid'
$queuePidPath = Join-Path $runtimePath 'queue.pid'
$mutex = New-Object System.Threading.Mutex($false, 'Local\KhalooWindowsLauncher')

function Show-LaunchError([string] $message) {
    Add-Type -AssemblyName System.Windows.Forms
    [void] [System.Windows.Forms.MessageBox]::Show($message, 'Khaloo', 'OK', 'Error')
}

function Get-ProjectProcess([string] $kind) {
    $escapedArtisan = [regex]::Escape($artisanPath)
    $commandPattern = 'artisan"?\s+' + [regex]::Escape($kind) + '(\s|$)'
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
        Where-Object { $_.CommandLine -match $escapedArtisan -and $_.CommandLine -match $commandPattern }
}

function Test-HttpReady {
    try {
        $response = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 2 -ErrorAction Stop
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 500
    } catch [System.Net.WebException] {
        if ($_.Exception.Response) { return [int] $_.Exception.Response.StatusCode -lt 500 }
        return $false
    } catch { return $false }
}

function Get-MobileUrl {
    try {
        $addresses = Get-NetIPAddress -AddressFamily IPv4 -AddressState Preferred -ErrorAction Stop |
            Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' -and (Get-NetAdapter -InterfaceIndex $_.InterfaceIndex -ErrorAction SilentlyContinue).Status -eq 'Up' } |
            Sort-Object @{ Expression = { if ($_.InterfaceAlias -match 'Wi-Fi|Wireless|WLAN') { 0 } elseif ($_.InterfaceAlias -match 'Ethernet') { 1 } else { 2 } } }, InterfaceMetric
        $address = $addresses | Select-Object -First 1
        if ($address) { return "http://$($address.IPAddress):$Port" }
    } catch {}
    return $null
}

try {
    if (-not $mutex.WaitOne(30000)) { exit 1 }
    Set-Location -LiteralPath $projectRoot
    New-Item -ItemType Directory -Path $runtimePath -Force | Out-Null

    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) { Show-LaunchError 'PHP was not found in PATH.'; exit 1 }
    $phpPath = $php.Source
    & $phpPath $artisanPath migrate:status *> $null
    if ($LASTEXITCODE -ne 0) { Show-LaunchError 'MySQL is unavailable. Check the database service and .env.'; exit 1 }

    $server = @(Get-ProjectProcess 'serve') | Select-Object -First 1
    if (-not $server) {
        if (Test-HttpReady) { Show-LaunchError 'Port 8010 is already in use.'; exit 1 }
        $server = Start-Process -FilePath $phpPath -ArgumentList ('"{0}" serve --host={1} --port={2}' -f $artisanPath, $HostAddress, $Port) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimePath 'server.out.log') -RedirectStandardError (Join-Path $runtimePath 'server.err.log')
    }
    Set-Content -LiteralPath $serverPidPath -Value $(if ($server -is [System.Diagnostics.Process]) { $server.Id } else { $server.ProcessId }) -Encoding Ascii

    $queue = @(Get-ProjectProcess 'queue:work') | Select-Object -First 1
    if (-not $queue) {
        $queue = Start-Process -FilePath $phpPath -ArgumentList ('"{0}" queue:work --sleep=2 --tries=3 --timeout=600' -f $artisanPath) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimePath 'queue.out.log') -RedirectStandardError (Join-Path $runtimePath 'queue.err.log')
    }
    Set-Content -LiteralPath $queuePidPath -Value $(if ($queue -is [System.Diagnostics.Process]) { $queue.Id } else { $queue.ProcessId }) -Encoding Ascii

    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if (Test-HttpReady) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { Show-LaunchError 'Khaloo server did not start. Check storage/app/runtime.'; exit 1 }
    $mobileUrl = Get-MobileUrl
    if ($mobileUrl) { Set-Content -LiteralPath (Join-Path $runtimePath 'mobile-url.txt') -Value $mobileUrl -Encoding Ascii }
    Start-Process $url
} catch {
    try { Set-Content -LiteralPath (Join-Path $runtimePath 'launcher.err.log') -Value $_.Exception.Message -Encoding UTF8 } catch {}
    Show-LaunchError 'Khaloo could not be started. Check storage/app/runtime.'
    exit 1
} finally {
    if ($mutex) {
        try { $mutex.ReleaseMutex() } catch {}
        $mutex.Dispose()
    }
}
