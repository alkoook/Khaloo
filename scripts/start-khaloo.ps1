$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$artisanPath = Join-Path $ProjectRoot 'artisan'
$runtimePath = Join-Path $ProjectRoot 'storage\app\runtime'
$HostAddress = '0.0.0.0'
$Port = 8010
$RequiredPhpVersion = [version] '8.4.1'
$PreferredPhpPath = 'C:\php-8.5\php.exe'
$url = "http://127.0.0.1:$Port"
$serverPidPath = Join-Path $runtimePath 'server.pid'
$queuePidPath = Join-Path $runtimePath 'queue.pid'
$mutex = New-Object System.Threading.Mutex($false, 'Local\KhalooWindowsLauncher')

function Show-LaunchError([string] $message) {
    Add-Type -AssemblyName System.Windows.Forms
    [void] [System.Windows.Forms.MessageBox]::Show($message, 'Khaloo', 'OK', 'Error')
}

function Get-ArabicMessage([string] $encoded) {
    return [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String($encoded))
}

function Find-PhpExecutable {
    $candidates = @($PreferredPhpPath)
    $phpOnPath = Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    $phpFromPath = if ($phpOnPath) { if ($phpOnPath.Path) { $phpOnPath.Path } else { $phpOnPath.Source } }
    if ($phpFromPath) { $candidates += $phpFromPath }
    $candidates += 'C:\xampp\php\php.exe'

    $detected = @()
    foreach ($candidate in ($candidates | Select-Object -Unique)) {
        if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) { continue }
        try {
            $versionText = (& $candidate -r 'echo PHP_VERSION;' 2>$null).Trim()
            $version = [version] $versionText
            $detected += "$candidate ($versionText)"
            if ($version -ge $RequiredPhpVersion) {
                return [pscustomobject] @{ Path = $candidate; Version = $versionText; Detected = $detected }
            }
        } catch {
            $detected += "$candidate (unknown)"
        }
    }

    return [pscustomobject] @{ Path = $null; Version = $null; Detected = $detected }
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
    Set-Location -LiteralPath $ProjectRoot
    New-Item -ItemType Directory -Path $runtimePath -Force | Out-Null

    $php = Find-PhpExecutable
    if (-not $php.Path) {
        $detectedVersions = if ($php.Detected.Count) { $php.Detected -join "`n" } else { Get-ArabicMessage '2YTZhSDZitiq2YUg2KfZhNi52KvZiNixINi52YTZiSBQSFAu' }
        $phpError = (Get-ArabicMessage '2KXYtdiv2KfYsSBQSFAg2YXZgdmC2YjYryDYo9mIINi62YrYsSDZhdiq2YjYp9mB2YIu') + "`n"
        $phpError += ((Get-ArabicMessage '2KfZhNil2LXYr9in2LEg2KfZhNmF2LfZhNmI2Kg6IFBIUCB7MH0g2KPZiCDYo9it2K/Yqy4=') -f $RequiredPhpVersion) + "`n"
        $phpError += (Get-ArabicMessage '2KfZhNil2LXYr9in2LHYp9iqINin2YTZhdmD2KrYtNmB2Kk6') + "`n$detectedVersions"
        Show-LaunchError $phpError
        exit 1
    }
    $phpPath = $php.Path
    $migrationStatus = & $phpPath $artisanPath migrate:status 2>&1
    if ($LASTEXITCODE -ne 0) {
        $migrationStatus | Set-Content -LiteralPath (Join-Path $runtimePath 'migration-status.err.log') -Encoding UTF8
        $migrationError = (Get-ArabicMessage '2KrYudiw2LEg2KfZhNiq2K3ZgtmCINmF2YYg2K3Yp9mE2Kkg2KrYsdit2YrZhNin2Kog2YLYp9i52K/YqSDYp9mE2KjZitin2YbYp9iqLiDZhNinINmK2LnZhtmKINiw2YTZgyDYqNin2YTYttix2YjYsdipINmI2KzZiNivINiu2LfYoyDZgdmKIE15U1FMLg==') + "`n"
        $migrationError += Get-ArabicMessage '2LHYp9is2Lkgc3RvcmFnZS9hcHAvcnVudGltZS9taWdyYXRpb24tc3RhdHVzLmVyci5sb2cg2YTZhNiq2YHYp9i12YrZhC4='
        Show-LaunchError $migrationError
        exit 1
    }

    $server = @(Get-ProjectProcess 'serve') | Select-Object -First 1
    if (-not $server) {
        if ((Test-HttpReady)) { Show-LaunchError (Get-ArabicMessage '2KfZhNmF2YbZgdiwIDgwMTAg2YXYs9iq2K7Yr9mFINmF2YYg2KrYt9io2YrZgiDYotiu2LEu'); exit 1 }
        $server = Start-Process -FilePath $phpPath -ArgumentList ('"{0}" serve --host={1} --port={2}' -f $artisanPath, $HostAddress, $Port) -WorkingDirectory $ProjectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimePath 'server.out.log') -RedirectStandardError (Join-Path $runtimePath 'server.err.log')
    }
    Set-Content -LiteralPath $serverPidPath -Value $(if ($server -is [System.Diagnostics.Process]) { $server.Id } else { $server.ProcessId }) -Encoding Ascii

    $queue = @(Get-ProjectProcess 'queue:work') | Select-Object -First 1
    if (-not $queue) {
        $queue = Start-Process -FilePath $phpPath -ArgumentList ('"{0}" queue:work --sleep=2 --tries=3 --timeout=600' -f $artisanPath) -WorkingDirectory $ProjectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimePath 'queue.out.log') -RedirectStandardError (Join-Path $runtimePath 'queue.err.log')
    }
    Set-Content -LiteralPath $queuePidPath -Value $(if ($queue -is [System.Diagnostics.Process]) { $queue.Id } else { $queue.ProcessId }) -Encoding Ascii

    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if ((Test-HttpReady)) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { Show-LaunchError (Get-ArabicMessage '2KrYudiw2LEg2KrYtNi62YrZhCDYrtin2K/ZhSBLaGFsb28uINix2KfYrNi5IHN0b3JhZ2UvYXBwL3J1bnRpbWUu'); exit 1 }
    $mobileUrl = Get-MobileUrl
    if ($mobileUrl) { Set-Content -LiteralPath (Join-Path $runtimePath 'mobile-url.txt') -Value $mobileUrl -Encoding Ascii }
    Start-Process $url
} catch {
    try { Set-Content -LiteralPath (Join-Path $runtimePath 'launcher.err.log') -Value $_.Exception.Message -Encoding UTF8 } catch {}
    Show-LaunchError (Get-ArabicMessage '2KrYudiw2LEg2KrYtNi62YrZhCBLaGFsb28uINix2KfYrNi5IHN0b3JhZ2UvYXBwL3J1bnRpbWUu')
    exit 1
} finally {
    if ($mutex) {
        try { $mutex.ReleaseMutex() } catch {}
        $mutex.Dispose()
    }
}
