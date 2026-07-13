[CmdletBinding()]
param(
    [switch]$Build,
    [switch]$VerifyRegistration,
    [int]$TimeoutSeconds = 180
)

. "$PSScriptRoot\_common.ps1"

Enter-RepoRoot
Assert-LocalEnvIsValid

Ensure-DockerNetwork 'osuweb_external'
Ensure-DockerNetwork 'bancho_network'

if ($Build) {
    docker compose build php assets
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

docker compose up -d db redis elasticsearch
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
Wait-ComposeServicesHealthy -Services @('db', 'redis', 'elasticsearch') -TimeoutSeconds $TimeoutSeconds

docker compose run --rm php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

docker compose run --rm php artisan migrate --force
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Ensure-UsersSearchIndex

docker compose up -d --force-recreate php assets nginx
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
Wait-ComposeServicesHealthy -Services @('php', 'assets', 'nginx', 'db', 'redis', 'elasticsearch') -TimeoutSeconds $TimeoutSeconds

$baseUrl = Get-LocalAppUrl
$health = Invoke-CheckedUrl "$baseUrl/healthz" 200
$homeResponse = Invoke-CheckedUrl $baseUrl 200

Write-Host "Health check: HTTP $($health.StatusCode)"
Write-Host "Homepage: HTTP $($homeResponse.StatusCode), $($homeResponse.Content.Length) bytes"

if ($VerifyRegistration) {
    $registration = Invoke-DisposableRegistrationFlow -BaseUrl $baseUrl
    Write-Host "Disposable registration: $($registration.Username), register HTTP $($registration.RegisterStatus), login HTTP $($registration.LoginStatus), settings HTTP $($registration.SettingsStatus), $($registration.SettingsBytes) bytes"
}

Write-Host "Local frontend stack is ready: $baseUrl"
