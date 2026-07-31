# Starts the local preview of the WireGuard Provision page.
#
#   powershell -ExecutionPolicy Bypass -File preview\serve.ps1
#
# Then open http://127.0.0.1:8088/  (Ctrl+C to stop)

param(
    [int]$Port = 8088
)

$root = $PSScriptRoot
$php  = Join-Path $root "..\.tools\php\php.exe"

if (-not (Test-Path $php)) {
    Write-Host "PHP not found at $php" -ForegroundColor Red
    Write-Host "Download the portable build into .tools\php (see preview\README.md)."
    exit 1
}

Write-Host ""
Write-Host "  WireGuard Provision - local preview" -ForegroundColor Cyan
Write-Host "  http://127.0.0.1:$Port/" -ForegroundColor Green
Write-Host ""
Write-Host "  /wgeasy/vpn_wg_provision.php   the page"
Write-Host "  /_state                        config.json, .conf files, commands, mail outbox"
Write-Host "  /_reset                        wipe the fake firewall state"
Write-Host ""
Write-Host "  Ctrl+C to stop"
Write-Host ""

& $php -S "127.0.0.1:$Port" -t $root (Join-Path $root "router.php")
