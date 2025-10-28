param(
    [string]$ApiDir,                 # required
    [int]   $Port        = 8000,     # keep in sync with Moodle setting
    [string]$Bind        = "127.0.0.1",
    [switch]$RecreateVenv,
    [string]$ApiToken    = ""        # optional: exported to SPE_API_TOKEN
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if (-not (Test-Path $ApiDir)) {
    throw "ApiDir not found: $ApiDir"
}
Set-Location -Path $ApiDir

# -------- VENV ---------------------------------------------------------------
if ($RecreateVenv -and (Test-Path ".venv")) {
    Write-Host "Removing existing .venv..."
    Remove-Item -Recurse -Force ".venv" -ErrorAction SilentlyContinue
}

if (-not (Test-Path ".venv")) {
    if (Get-Command py -ErrorAction SilentlyContinue) {
        py -3 -m venv .venv
    } else {
        python -m venv .venv
    }
}

$VenvPath = (Resolve-Path ".venv").Path
$Py       = Join-Path $VenvPath "Scripts\python.exe"

# -------- PIP (use python -m pip with pip 25.x) ------------------------------
& $Py -m pip install --upgrade pip --disable-pip-version-check
& $Py -m pip install --upgrade uvicorn fastapi textblob vaderSentiment

# -------- ENV ----------------------------------------------------------------
$env:SPE_BIND = $Bind
$env:PORT     = "$Port"
if ($ApiToken -ne "") { $env:SPE_API_TOKEN = $ApiToken }

# -------- LOGS / PID ---------------------------------------------------------
$LogOut = Join-Path $ApiDir "api_out.txt"   # stdout
$LogErr = Join-Path $ApiDir "api_err.txt"   # stderr
$PidFile= Join-Path $ApiDir "sentiment_api.pid"

# start clean (comment these two if you want to append)
Remove-Item -Force $LogOut -ErrorAction SilentlyContinue
Remove-Item -Force $LogErr -ErrorAction SilentlyContinue

# -------- FREE PORT (prevents WinError 10048) --------------------------------

try {
    $owners = (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
               Select-Object -ExpandProperty OwningProcess -Unique)
} catch {
    # Fallback for older PS: use netstat parsing
    $owners = (netstat -ano | Select-String (":$Port\s+LISTENING\s+(\d+)") |
               ForEach-Object { $_.Matches[0].Groups[1].Value } | Select-Object -Unique)
}

foreach ($owner in $owners) {
    try {
        if ($owner -match '^\d+$') {
            Write-Host ("Stopping process using port {0} (PID {1})" -f $Port, $owner)
            Stop-Process -Id ([int]$owner) -Force -ErrorAction SilentlyContinue
        }
    } catch { }
}


# -------- START UVICORN (array args; separate stdout/stderr) ---------------
$ArgList = @(
    "-m", "uvicorn",
    "sentiment_api:app",
    "--host", $Bind,
    "--port", $Port.ToString()
)

$proc = Start-Process -FilePath $Py -ArgumentList $ArgList `
        -WorkingDirectory $ApiDir -NoNewWindow `
        -RedirectStandardOutput $LogOut -RedirectStandardError $LogErr `
        -PassThru

# After launch, find the PID that is actually LISTENING on the port
$listenerPid = $null
$deadline = (Get-Date).AddSeconds(8)
while (-not $listenerPid -and (Get-Date) -lt $deadline) {
    Start-Sleep -Milliseconds 300
    try {
        $pids = (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
                 Select-Object -ExpandProperty OwningProcess -Unique)
    } catch {
        $pids = (netstat -ano | Select-String (":$Port\s+LISTENING\s+(\d+)") |
                 ForEach-Object { $_.Matches[0].Groups[1].Value } | Select-Object -Unique)
    }
    if ($pids -and $pids.Count -ge 1) { $listenerPid = [int]$pids[0] }
}

# Save the real listener PID if found, else the parent python PID
if ($listenerPid) {
    Set-Content -Path $PidFile -Value $listenerPid -Encoding Ascii -NoNewline
    Write-Host ("Listener PID: {0} (parent PID: {1})" -f $listenerPid, $proc.Id)
} else {
    Set-Content -Path $PidFile -Value $proc.Id -Encoding Ascii -NoNewline
    Write-Host ("Could not detect listener PID. Saved parent PID: {0}" -f $proc.Id)
}

$startedMsg = "Started uvicorn (parent PID {0}) on http://{1}:{2}/" -f $proc.Id, $Bind, $Port
$logsMsg    = "Logs: {0} (stdout), {1} (stderr)" -f $LogOut, $LogErr
Write-Host $startedMsg
Write-Host $logsMsg
