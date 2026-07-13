[CmdletBinding(DefaultParameterSetName = 'DryRun')]
param(
    [Parameter(ParameterSetName = 'DryRun')]
    [switch]$DryRun,

    [Parameter(ParameterSetName = 'Import')]
    [switch]$Import,

    [Parameter(ParameterSetName = 'Import')]
    [switch]$ForceLocalReplace,

    [switch]$SkipMedia,

    [string[]]$Tables = @()
)

. "$PSScriptRoot\_common.ps1"

function Get-RequiredProcessEnv {
    param([Parameter(Mandatory = $true)][string]$Name)

    $value = [Environment]::GetEnvironmentVariable($Name, 'Process')
    if ([string]::IsNullOrWhiteSpace($value)) {
        throw "Missing production mirror environment variable: $Name."
    }

    return $value
}

function Quote-MySqlIdentifier {
    param([Parameter(Mandatory = $true)][string]$Name)

    if ($Name -notmatch '^[A-Za-z0-9_]+$') {
        throw "Unsafe MySQL identifier: $Name."
    }

    return "``$Name``"
}

function Invoke-LocalMysql {
    param([Parameter(Mandatory = $true)][string]$Sql)

    $Sql | & docker compose exec -T `
        -e "MYSQL_PWD=$script:LocalDbPassword" `
        db mysql --user=$script:LocalDbUser $script:LocalDbName

    if ($LASTEXITCODE -ne 0) {
        throw 'Local MySQL command failed.'
    }
}

Enter-RepoRoot
$envValues = Get-LocalEnv
$appUrl = Get-EnvValue $envValues 'APP_URL' 'http://127.0.0.1:8088'
if ($appUrl.TrimEnd('/') -ne 'http://127.0.0.1:8088') {
    throw "Refusing production mirror: local APP_URL must be http://127.0.0.1:8088, got $appUrl."
}

$localDbHost = Get-EnvValue $envValues 'DB_HOST' ''
if ($localDbHost -notin @('', 'db', '127.0.0.1', 'localhost')) {
    throw "Refusing production mirror: local DB_HOST looks non-local ($localDbHost)."
}

$script:LocalDbName = Get-EnvValue $envValues 'DB_DATABASE' 'osu'
$script:LocalDbUser = Get-EnvValue $envValues 'DB_USERNAME' 'osuweb'
$script:LocalDbPassword = Get-EnvValue $envValues 'DB_PASSWORD'
if ([string]::IsNullOrWhiteSpace($script:LocalDbPassword)) {
    throw 'Refusing production mirror: local DB_PASSWORD is missing from .env.'
}

$prodHost = Get-RequiredProcessEnv 'M1PP_PROD_DB_HOST'
$prodPort = [int](Get-RequiredProcessEnv 'M1PP_PROD_DB_PORT')
$prodDb = Get-RequiredProcessEnv 'M1PP_PROD_DB_DATABASE'
$prodUser = Get-RequiredProcessEnv 'M1PP_PROD_DB_USERNAME'
$prodPassword = Get-RequiredProcessEnv 'M1PP_PROD_DB_PASSWORD'

if ($prodHost -in @('db', '127.0.0.1', 'localhost', 'm1-web-local-dev-db-1')) {
    throw "Refusing production mirror: production DB host must not be local-looking ($prodHost)."
}

if (-not $Import -and -not $DryRun) {
    $DryRun = $true
}

if ($Import -and -not $ForceLocalReplace) {
    throw 'Refusing local import: -Import requires -ForceLocalReplace.'
}

$defaultTables = @(
    'phpbb_users',
    'phpbb_groups',
    'phpbb_user_group',
    'osu_badges',
    'osu_user_achievements',
    'osu_user_banhistory',
    'osu_user_month_playcount',
    'osu_user_performance_rank',
    'osu_user_performance_rank_exp',
    'osu_user_performance_rank_highest',
    'osu_user_replayswatched',
    'osu_user_stats',
    'osu_user_stats_ap',
    'osu_user_stats_fruits',
    'osu_user_stats_mania',
    'osu_user_stats_taiko',
    'user_country_history',
    'user_profile_customizations',
    'user_summaries',
    'm1pposu_official_connections',
    'm1pposu_account_import_snapshots',
    'm1pposu_account_import_requests',
    'm1pposu_imported_official_score_summaries'
)

if ($Tables.Count -eq 0) {
    $envTables = [Environment]::GetEnvironmentVariable('M1PP_PROD_DB_TABLES', 'Process')
    $Tables = if ([string]::IsNullOrWhiteSpace($envTables)) {
        $defaultTables
    } else {
        @($envTables.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
    }
}

$Tables = @($Tables | Sort-Object -Unique)
foreach ($table in $Tables) {
    Quote-MySqlIdentifier $table | Out-Null
}

Write-Host 'Production-to-local mirror plan'
Write-Host "  source: $prodUser@$prodHost`:$prodPort/$prodDb (read-only expected)"
Write-Host "  target: local Docker db service / $script:LocalDbName"
Write-Host "  tables: $($Tables -join ', ')"
Write-Host '  excluded: sessions, OAuth clients/tokens/secrets, password reset/auth token tables'
Write-Host '  sanitize: phpbb_users password/email/remember/activation fields after local import'
if ($SkipMedia) {
    Write-Host '  media: skipped'
} else {
    Write-Host '  media: not copied by this script; sync media separately with an explicit read-only tool'
}

if ($DryRun -and -not $Import) {
    Write-Host 'Dry run only. Re-run with -Import -ForceLocalReplace to replace the selected local tables.'
    exit 0
}

$items = @(docker compose ps --format json | ConvertFrom-Json)
$dbItem = $items | Where-Object { $_.Service -eq 'db' } | Select-Object -First 1
if ($null -eq $dbItem -or $dbItem.State -ne 'running') {
    throw 'Local db service is not running. Start the local stack before importing.'
}

$dumpDir = Join-Path (Get-RepoRoot) 'storage\private-server-imports'
New-Item -ItemType Directory -Force -Path $dumpDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$dumpPath = Join-Path $dumpDir "production-web-$stamp.sql"

$dumpArgs = @(
    'run', '--rm',
    '-e', "MYSQL_PWD=$prodPassword",
    '--entrypoint', 'mysqldump',
    'mysql:8.4',
    "--host=$prodHost",
    "--port=$prodPort",
    "--user=$prodUser",
    '--single-transaction',
    '--quick',
    '--set-gtid-purged=OFF',
    '--column-statistics=0',
    '--skip-triggers',
    '--no-create-info',
    '--replace',
    '--default-character-set=utf8mb4',
    $prodDb
) + $Tables

Write-Host "Dumping selected production tables to ignored local file: $dumpPath"
& docker @dumpArgs > $dumpPath
if ($LASTEXITCODE -ne 0) {
    Remove-Item -LiteralPath $dumpPath -Force -ErrorAction SilentlyContinue
    throw 'Production dump failed. Confirm read-only credentials, network/VPN access, and table names.'
}

$truncateSql = "SET FOREIGN_KEY_CHECKS=0;`n"
foreach ($table in $Tables) {
    $truncateSql += "TRUNCATE TABLE $(Quote-MySqlIdentifier $table);`n"
}
$truncateSql += "SET FOREIGN_KEY_CHECKS=1;`n"

Write-Host 'Replacing selected local tables.'
Invoke-LocalMysql $truncateSql

$containerDumpPath = "/tmp/m1pposu-production-web-$stamp.sql"
& docker compose cp $dumpPath "db:$containerDumpPath"
if ($LASTEXITCODE -ne 0) {
    throw 'Failed to copy dump into local db container.'
}

try {
    & docker compose exec -T `
        -e "MYSQL_PWD=$script:LocalDbPassword" `
        -e "MYSQL_USER=$script:LocalDbUser" `
        -e "MYSQL_DATABASE=$script:LocalDbName" `
        db sh -lc 'mysql --user="$MYSQL_USER" "$MYSQL_DATABASE" < "$0"' $containerDumpPath
    if ($LASTEXITCODE -ne 0) {
        throw 'Local import failed.'
    }
} finally {
    & docker compose exec -T db rm -f $containerDumpPath | Out-Null
}

$sanitizeSql = @'
UPDATE phpbb_users
SET
  user_password = '',
  user_email = CONCAT('local+', user_id, '@example.test'),
  user_ip = '',
  user_last_confirm_key = '',
  user_actkey = '',
  user_newpasswd = '',
  remember_token = NULL;
'@
Write-Host 'Sanitizing local imported auth/contact fields.'
Invoke-LocalMysql $sanitizeSql

Write-Host 'Rebuilding local users search index.'
& docker compose exec -T php php artisan es:index-documents --types=users --no-interaction
if ($LASTEXITCODE -ne 0) {
    throw 'Failed to rebuild local users search index.'
}

Write-Host 'Production mirror import complete. Run Grant-LocalAdmin.ps1 for local staff access if needed.'
