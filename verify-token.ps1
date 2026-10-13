# =============================================================================
# verify-token.ps1
# Sanity check that HF_TOKEN is present in the environment and that the
# hf-image-request.ps1 helper can be located and parsed.
#
# The token is NEVER stored in this file. Set it in your own environment first:
#     setx HF_TOKEN "hf_xxxxxxxxxxxxxxxxxxxx"
# then open a NEW PowerShell window so the variable is loaded.
#
# Usage:
#     .\verify-token.ps1
#     .\verify-token.ps1 -ScriptPath "C:\path\to\hf-image-request.ps1"
# =============================================================================
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$ScriptPath = (Join-Path $PSScriptRoot 'hf-image-request.ps1')
)

Write-Host "Checking token: $env:HF_TOKEN" -ForegroundColor Cyan
if ($env:HF_TOKEN) {
    Write-Host "Token is set - first 8 chars: $($env:HF_TOKEN.Substring(0,8))..." -ForegroundColor Green
} else {
    Write-Host "Token not set. Run:  setx HF_TOKEN `"hf_...`"  then restart PowerShell." -ForegroundColor Red
    exit 1
}

# Check the helper script exists
if (Test-Path $ScriptPath) {
    Write-Host "Script found at: $ScriptPath" -ForegroundColor Green
} else {
    Write-Host "Script NOT found at: $ScriptPath" -ForegroundColor Red
    exit 1
}

# Verify the helper parses without invoking a real API call.
try {
    $null = [System.Management.Automation.Language.Parser]::ParseFile($ScriptPath, [ref]$null, [ref]$null)
    Write-Host "Script syntax appears valid" -ForegroundColor Green
} catch {
    Write-Host "Script syntax error: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
