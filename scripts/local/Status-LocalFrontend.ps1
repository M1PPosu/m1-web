[CmdletBinding()]
param(
    [switch]$VerifyRegistration
)

. "$PSScriptRoot\_common.ps1"

Enter-RepoRoot
$hadFailure = $false

docker compose ps

$items = @(docker compose ps --format json | ConvertFrom-Json)
foreach ($service in @('php', 'nginx')) {
    $item = $items | Where-Object { $_.Service -eq $service } | Select-Object -First 1
    $health = Get-ObjectPropertyString $item 'Health'
    $state = Get-ObjectPropertyString $item 'State'

    if ($null -eq $item -or $state -ne 'running' -or ($health -ne '' -and $health -ne 'healthy')) {
        Write-Host ""
        Write-Host "Recent $service logs:"
        docker compose logs --no-color --tail=80 $service
    }
}

try {
    $baseUrl = Get-LocalAppUrl
    $healthResponse = Invoke-CheckedUrl "$baseUrl/healthz" 200
    Write-Host "Health check: HTTP $($healthResponse.StatusCode)"
} catch {
    $hadFailure = $true
    Write-Host "Health check failed: $($_.Exception.Message)"
}

try {
    $baseUrl = Get-LocalAppUrl
    $homeResponse = Invoke-CheckedUrl $baseUrl 200
    Write-Host "Homepage: HTTP $($homeResponse.StatusCode), $($homeResponse.Content.Length) bytes"
} catch {
    $hadFailure = $true
    Write-Host "Homepage check failed: $($_.Exception.Message)"
}

if ($VerifyRegistration) {
    try {
        $registration = Invoke-DisposableRegistrationFlow -BaseUrl (Get-LocalAppUrl)
        Write-Host "Disposable registration: $($registration.Username), register HTTP $($registration.RegisterStatus), login HTTP $($registration.LoginStatus), settings HTTP $($registration.SettingsStatus), $($registration.SettingsBytes) bytes"
    } catch {
        $hadFailure = $true
        Write-Host "Disposable registration failed: $($_.Exception.Message)"
    }
}

if ($hadFailure) {
    exit 1
}
