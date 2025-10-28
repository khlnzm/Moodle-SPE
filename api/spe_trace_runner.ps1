param([string]$ApiDir, [string]$Bootstrap, [string]$TracePath)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Set-Location -Path $ApiDir
Set-Content -Path $TracePath -Value "" -Encoding UTF8
$VerbosePreference = 'Continue'
Set-PSDebug -Trace 1
try {
    & $Bootstrap -ApiDir $ApiDir *>&1 | Tee-Object -FilePath $TracePath -Append
} catch {
    "ERROR: $($_ | Out-String)" | Tee-Object -FilePath $TracePath -Append | Out-Null
    exit 1
} finally {
    Set-PSDebug -Trace 0
}