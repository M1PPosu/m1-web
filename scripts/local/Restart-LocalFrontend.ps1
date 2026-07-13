[CmdletBinding()]
param(
    [switch]$Build,
    [switch]$VerifyRegistration,
    [int]$TimeoutSeconds = 180
)

& "$PSScriptRoot\Stop-LocalFrontend.ps1"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& "$PSScriptRoot\Start-LocalFrontend.ps1" -Build:$Build -VerifyRegistration:$VerifyRegistration -TimeoutSeconds $TimeoutSeconds
exit $LASTEXITCODE
