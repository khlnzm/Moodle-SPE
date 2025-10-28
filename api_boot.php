<?php
// mod/spe/api_boot.php
// Minimal helper to start the Windows FastAPI service by running spe_api_bootstrap.ps1.
// No UI. No redirects. Call spe_start_sentiment_api($cmid) from server-side code.

defined('MOODLE_INTERNAL') || die();

function spe_start_sentiment_api(int $cmid): array {
    global $CFG, $DB;

    // Capability check (admin/manager at the activity context).
    $cm = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    require_login($course, true, $cm);
    $context = context_module::instance($cm->id);
    require_capability('moodle/site:config', $context);

    // Windows only.
    $iswindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    if (!$iswindows) {
        return [false, 'Sentiment API bootstrap is Windows-only in this configuration.'];
    }

    // Paths.
    $apidir    = $CFG->dirroot . '/mod/spe/api';
    $bootstrap = $apidir . DIRECTORY_SEPARATOR . 'spe_api_bootstrap.ps1';
    $traceps1  = $apidir . DIRECTORY_SEPARATOR . 'spe_trace_runner.ps1';
    $tracelog  = $apidir . DIRECTORY_SEPARATOR . 'bootstrap_trace.txt';

    if (!is_dir($apidir)) {
        return [false, 'API directory not found: ' . $apidir];
    }
    if (!file_exists($bootstrap)) {
        return [false, 'Bootstrap script not found: ' . $bootstrap];
    }

    // Write a wrapper that runs the bootstrap with step tracing and tees to bootstrap_trace.txt.
    $wrapper = <<<'PS1'
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
PS1;

    @file_put_contents($traceps1, $wrapper);

    // Execute synchronously so the caller can inspect success/failure.
    $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File ' .
           escapeshellarg($traceps1) .
           ' -ApiDir '   . escapeshellarg($apidir) .
           ' -Bootstrap ' . escapeshellarg($bootstrap) .
           ' -TracePath ' . escapeshellarg($tracelog);

    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);

    if ($code === 0) {
        return [true, 'Sentiment API bootstrap executed successfully.'];
    } else {
        // Don’t echo; return message for the caller to log or display.
        return [false, 'Bootstrap returned non-zero exit code. See: ' . $tracelog];
    }
}
