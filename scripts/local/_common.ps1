Set-StrictMode -Version 3.0
$ErrorActionPreference = 'Stop'

function Get-RepoRoot {
    return (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
}

function Enter-RepoRoot {
    Set-Location (Get-RepoRoot)
}

function Get-LocalEnv {
    $envPath = Join-Path (Get-RepoRoot) '.env'
    if (-not (Test-Path -LiteralPath $envPath)) {
        throw ".env is missing. Copy .env.example to .env and configure local values before starting."
    }

    $values = @{}
    foreach ($line in Get-Content -LiteralPath $envPath) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '' -or $trimmed.StartsWith('#')) {
            continue
        }

        $equals = $trimmed.IndexOf('=')
        if ($equals -lt 1) {
            continue
        }

        $key = $trimmed.Substring(0, $equals).Trim()
        $value = $trimmed.Substring($equals + 1).Trim()
        if (
            ($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))
        ) {
            $value = $value.Substring(1, $value.Length - 2)
        }

        $values[$key] = $value
    }

    return $values
}

function Get-LocalAppUrl {
    $envValues = Get-LocalEnv
    $appUrl = Get-EnvValue $envValues 'APP_URL' 'http://127.0.0.1:8088'

    return $appUrl.TrimEnd('/')
}

function Test-EnvTrue {
    param([string]$Value)

    if ($null -eq $Value) {
        return $false
    }

    return @('1', 'true', 'yes', 'on') -contains $Value.Trim().ToLowerInvariant()
}

function Get-EnvValue {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Values,
        [Parameter(Mandatory = $true)][string]$Key,
        [string]$Default = ''
    )

    if ($Values.ContainsKey($Key) -and $null -ne $Values[$Key]) {
        return [string]$Values[$Key]
    }

    return $Default
}

function Get-ObjectPropertyString {
    param(
        [Parameter(Mandatory = $true)]$Object,
        [Parameter(Mandatory = $true)][string]$Name
    )

    if ($null -eq $Object) {
        return ''
    }

    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property -or $null -eq $property.Value) {
        return ''
    }

    return [string]$property.Value
}

function Assert-LocalEnvIsValid {
    $envValues = Get-LocalEnv

    $privateServerEnabled = Test-EnvTrue (Get-EnvValue $envValues 'M1PP_PRIVATE_SERVER_ENABLED' 'false')
    $privateRegistrationEnabled = Test-EnvTrue (Get-EnvValue $envValues 'M1PP_PRIVATE_SERVER_REGISTRATION_ENABLED' 'false')

    if ($privateRegistrationEnabled -and -not $privateServerEnabled) {
        throw @(
            'Invalid .env: M1PP_PRIVATE_SERVER_REGISTRATION_ENABLED=true requires M1PP_PRIVATE_SERVER_ENABLED=true.',
            'For normal website registration, keep ALLOW_REGISTRATION=true and set M1PP_PRIVATE_SERVER_REGISTRATION_ENABLED=false.',
            'Only enable private-server registration with a configured private-server backend and database.'
        ) -join ' '
    }

    $webRegistrationEnabled = Test-EnvTrue (Get-EnvValue $envValues 'ALLOW_REGISTRATION' 'true')
    $webRegistrationMode = Test-EnvTrue (Get-EnvValue $envValues 'REGISTRATION_MODE_WEB' 'false')
    $verificationBypassed = Test-EnvTrue (Get-EnvValue $envValues 'USER_BYPASS_VERIFICATION' 'false')

    if ($webRegistrationEnabled -and -not $webRegistrationMode) {
        throw @(
            'Invalid local .env: ALLOW_REGISTRATION=true but REGISTRATION_MODE_WEB is not true.',
            'Set REGISTRATION_MODE_WEB=true to make /register and /users/create available for local web signups.'
        ) -join ' '
    }

    if ($webRegistrationEnabled -and -not $verificationBypassed) {
        throw @(
            'Invalid local .env: ALLOW_REGISTRATION=true but USER_BYPASS_VERIFICATION is not true.',
            'For this local stack, keep USER_BYPASS_VERIFICATION=true so web registration does not depend on verification emails.'
        ) -join ' '
    }

    if ($privateServerEnabled) {
        $required = @(
            'M1PP_PRIVATE_SERVER_BACKEND',
            'M1PP_PRIVATE_SERVER_DB_HOST',
            'M1PP_PRIVATE_SERVER_DB_PORT',
            'M1PP_PRIVATE_SERVER_DB_DATABASE',
            'M1PP_PRIVATE_SERVER_DB_USERNAME',
            'M1PP_PRIVATE_SERVER_DB_PASSWORD',
            'M1PP_BEATMAP_DOWNLOAD_URL',
            'M1PP_AVATAR_URL'
        )

        $missing = @($required | Where-Object { -not $envValues.ContainsKey($_) -or [string]::IsNullOrWhiteSpace($envValues[$_]) })
        if ($missing.Count -gt 0) {
            throw "Invalid .env: M1PP_PRIVATE_SERVER_ENABLED=true but required private-server settings are missing: $($missing -join ', ')."
        }

        if ($envValues['M1PP_PRIVATE_SERVER_BACKEND'] -ne 'bancho-py-ex') {
            throw 'Invalid .env: M1PP_PRIVATE_SERVER_BACKEND must be bancho-py-ex.'
        }
    }

    foreach ($key in @('DB_PASSWORD', 'MYSQL_APP_HOST', 'MYSQL_ROOT_PASSWORD_FILE')) {
        if (-not $envValues.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($envValues[$key])) {
            throw "Invalid .env: $key must be set for the local Docker stack."
        }
    }

    $rootPasswordFile = Join-Path (Get-RepoRoot) $envValues['MYSQL_ROOT_PASSWORD_FILE']
    if (-not (Test-Path -LiteralPath $rootPasswordFile)) {
        throw "Invalid .env: MYSQL_ROOT_PASSWORD_FILE points to a missing file: $($envValues['MYSQL_ROOT_PASSWORD_FILE'])."
    }

    foreach ($keyPath in @('storage\oauth-public.key', 'storage\oauth-private.key')) {
        $path = Join-Path (Get-RepoRoot) $keyPath
        if (-not (Test-Path -LiteralPath $path)) {
            throw "Missing Passport key: $keyPath. Generate local keys before starting the deployment stack."
        }
    }
}

function Ensure-DockerNetwork {
    param([Parameter(Mandatory = $true)][string]$Name)

    $exists = docker network ls --format '{{.Name}}' | Where-Object { $_ -eq $Name }
    if (-not $exists) {
        docker network create $Name | Out-Null
        Write-Host "Created Docker network: $Name"
    }
}

function Wait-ComposeServicesHealthy {
    param(
        [Parameter(Mandatory = $true)][string[]]$Services,
        [int]$TimeoutSeconds = 180
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        $items = @(docker compose ps --format json | ConvertFrom-Json)
        $notReady = @()

        foreach ($service in $Services) {
            $item = $items | Where-Object { $_.Service -eq $service } | Select-Object -First 1
            if ($null -eq $item) {
                $notReady += "${service}:not-created"
                continue
            }

            $health = Get-ObjectPropertyString $item 'Health'
            $state = Get-ObjectPropertyString $item 'State'
            if ($state -ne 'running' -or ($health -ne '' -and $health -ne 'healthy')) {
                $notReady += "${service}:$state/$health"
            }
        }

        if ($notReady.Count -eq 0) {
            return
        }

        Start-Sleep -Seconds 5
    } while ((Get-Date) -lt $deadline)

    throw "Timed out waiting for services: $($notReady -join ', ')."
}

function Invoke-CheckedUrl {
    param(
        [Parameter(Mandatory = $true)][string]$Url,
        [Parameter(Mandatory = $true)][int]$ExpectedStatusCode
    )

    $response = Invoke-WebRequest $Url -UseBasicParsing -TimeoutSec 30
    if ($response.StatusCode -ne $ExpectedStatusCode) {
        throw "Expected $Url to return HTTP $ExpectedStatusCode, got HTTP $($response.StatusCode)."
    }

    return $response
}

function Ensure-UsersSearchIndex {
    docker compose exec -T elasticsearch curl -fsS -o /dev/null http://localhost:9200/users
    if ($LASTEXITCODE -eq 0) {
        Write-Host 'Elasticsearch users index: present'
        return
    }

    Write-Host 'Elasticsearch users index: missing; creating via es:index-documents.'
    docker compose exec -T php php artisan es:index-documents --types=users --no-interaction
    if ($LASTEXITCODE -ne 0) {
        throw 'Failed to create Elasticsearch users index.'
    }
}

function Invoke-DisposableRegistrationFlow {
    param(
        [string]$BaseUrl = (Get-LocalAppUrl),
        [string]$Password = 'LocalPassw0rd!2345'
    )

    $BaseUrl = $BaseUrl.TrimEnd('/')
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    $registerPage = Invoke-WebRequest "$BaseUrl/register" -WebSession $session -UseBasicParsing -TimeoutSec 30
    if ($registerPage.StatusCode -ne 200) {
        throw "Expected registration page to return HTTP 200, got HTTP $($registerPage.StatusCode)."
    }

    $chars = (48..57) + (97..122)
    $suffix = -join ($chars | Get-Random -Count 6 | ForEach-Object { [char]$_ })
    $username = "cx$suffix"
    $email = "$username@example.test"

    $ajaxHeaders = @{
        Accept = 'application/json, text/javascript, */*; q=0.01'
        Origin = $BaseUrl
        Referer = "$BaseUrl/register"
        'X-Requested-With' = 'XMLHttpRequest'
    }

    $registerForm = @{
        'user[username]' = $username
        'user[user_email]' = $email
        'user[user_email_confirmation]' = $email
        'user[password]' = $Password
        'user[password_confirmation]' = $Password
    }

    try {
        $registerResponse = Invoke-WebRequest "$BaseUrl/users/store-web" `
            -WebSession $session `
            -Method Post `
            -Body $registerForm `
            -Headers $ajaxHeaders `
            -ContentType 'application/x-www-form-urlencoded' `
            -UseBasicParsing `
            -TimeoutSec 30
    } catch {
        throw "Disposable registration failed: $($_.Exception.Message)"
    }

    if ($registerResponse.StatusCode -ne 200) {
        throw "Expected disposable registration to return HTTP 200, got HTTP $($registerResponse.StatusCode)."
    }

    $loginHeaders = @{
        Accept = 'application/json, text/javascript, */*; q=0.01'
        Origin = $BaseUrl
        Referer = "$BaseUrl/session/new"
        'X-Requested-With' = 'XMLHttpRequest'
    }

    try {
        $loginResponse = Invoke-WebRequest "$BaseUrl/session" `
            -WebSession $session `
            -Method Post `
            -Body @{ username = $username; password = $Password } `
            -Headers $loginHeaders `
            -ContentType 'application/x-www-form-urlencoded' `
            -UseBasicParsing `
            -TimeoutSec 30
    } catch {
        throw "Disposable login failed: $($_.Exception.Message)"
    }

    if ($loginResponse.StatusCode -ne 200) {
        throw "Expected disposable login to return HTTP 200, got HTTP $($loginResponse.StatusCode)."
    }

    $settingsResponse = Invoke-WebRequest "$BaseUrl/home/account/edit" -WebSession $session -UseBasicParsing -TimeoutSec 30
    if ($settingsResponse.StatusCode -ne 200) {
        throw "Expected account settings to return HTTP 200, got HTTP $($settingsResponse.StatusCode)."
    }

    if (-not $settingsResponse.Content.Contains('account-edit')) {
        throw 'Account settings response did not contain the account-edit page marker.'
    }

    if (-not $settingsResponse.Content.Contains('official-osu-connection')) {
        throw 'Account settings response did not contain the official osu! connected-account section marker.'
    }

    return [pscustomobject]@{
        Username = $username
        RegisterStatus = $registerResponse.StatusCode
        LoginStatus = $loginResponse.StatusCode
        SettingsStatus = $settingsResponse.StatusCode
        SettingsBytes = $settingsResponse.Content.Length
    }
}
