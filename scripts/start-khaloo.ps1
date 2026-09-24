$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$artisanPath = Join-Path $projectRoot 'artisan'
$runtimePath = Join-Path $projectRoot 'storage\app\runtime'
$url = 'http://127.0.0.1:8000'
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

try {
    if (-not $mutex.WaitOne(30000)) { exit 1 }
    Set-Location -LiteralPath $projectRoot
    New-Item -ItemType Directory -Path $runtimePath -Force | Out-Null

    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) {
        Show-LaunchError ([regex]::Unescape('\u0644\u0645 \u064a\u062a\u0645 \u0627\u0644\u0639\u062b\u0648\u0631 \u0639\u0644\u0649 PHP. \u062b\u0628\u0651\u062a XAMPP \u0648\u0623\u0636\u0641 \u0645\u062c\u0644\u062f php \u0625\u0644\u0649 PATH \u062b\u0645 \u0623\u0639\u062f \u0627\u0644\u062a\u0634\u063a\u064a\u0644.'))
        exit 1
    }
    $phpPath = $php.Source

    & $phpPath $artisanPath migrate:status *> $null
    if ($LASTEXITCODE -ne 0) {
        Show-LaunchError ([regex]::Unescape('\u062a\u0639\u0630\u0631 \u0627\u0644\u0627\u062a\u0635\u0627\u0644 \u0628\u0642\u0627\u0639\u062f\u0629 \u0627\u0644\u0628\u064a\u0627\u0646\u0627\u062a. \u062a\u0623\u0643\u062f \u0623\u0646 \u062e\u062f\u0645\u0629 MySQL \u062a\u0639\u0645\u0644 \u0648\u0623\u0646 \u0625\u0639\u062f\u0627\u062f\u0627\u062a .env \u0635\u062d\u064a\u062d\u0629.'))
        exit 1
    }

    $server = @(Get-ProjectProcess 'serve') | Select-Object -First 1
    if (-not $server) {
        if (Test-HttpReady) {
            Show-LaunchError ([regex]::Unescape('\u0627\u0644\u0645\u0646\u0641\u0630 8000 \u0645\u0633\u062a\u062e\u062f\u0645 \u0628\u0648\u0627\u0633\u0637\u0629 \u062a\u0637\u0628\u064a\u0642 \u0622\u062e\u0631. \u0623\u063a\u0644\u0642\u0647 \u062b\u0645 \u0623\u0639\u062f \u062a\u0634\u063a\u064a\u0644 Khaloo.'))
            exit 1
        }
        $server = Start-Process -FilePath $phpPath -ArgumentList ('"{0}" serve --host=127.0.0.1 --port=8000' -f $artisanPath) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimePath 'server.out.log') -RedirectStandardError (Join-Path $runtimePath 'server.err.log')
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
    if (-not $ready) {
        Show-LaunchError ([regex]::Unescape('\u0644\u0645 \u064a\u0628\u062f\u0623 \u062e\u0627\u062f\u0645 Khaloo. \u062a\u062d\u0642\u0642 \u0645\u0646 \u0645\u0644\u0641\u0627\u062a \u0627\u0644\u062a\u0634\u063a\u064a\u0644 \u0641\u064a storage/app/runtime.'))
        exit 1
    }
    Start-Process $url
} catch {
    try { Set-Content -LiteralPath (Join-Path $runtimePath 'launcher.err.log') -Value $_.Exception.Message -Encoding UTF8 } catch {}
    Show-LaunchError ([regex]::Unescape('\u062a\u0639\u0630\u0631 \u062a\u0634\u063a\u064a\u0644 Khaloo. \u062a\u062d\u0642\u0642 \u0645\u0646 \u0645\u0644\u0641\u0627\u062a \u0627\u0644\u062a\u0634\u063a\u064a\u0644 \u0641\u064a storage/app/runtime.'))
    exit 1
} finally {
    if ($mutex) {
        try { $mutex.ReleaseMutex() } catch {}
        $mutex.Dispose()
    }
}
