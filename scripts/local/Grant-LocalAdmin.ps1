[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Username
)

. "$PSScriptRoot\_common.ps1"

function Sql-String {
    param([Parameter(Mandatory = $true)][string]$Value)

    return "'$($Value.Replace("'", "''"))'"
}

Enter-RepoRoot
$envValues = Get-LocalEnv
$appUrl = Get-EnvValue $envValues 'APP_URL' 'http://127.0.0.1:8088'
if ($appUrl.TrimEnd('/') -ne 'http://127.0.0.1:8088') {
    throw "Refusing local staff grant: APP_URL must be http://127.0.0.1:8088, got $appUrl."
}

$dbHost = Get-EnvValue $envValues 'DB_HOST' ''
if ($dbHost -notin @('', 'db', '127.0.0.1', 'localhost')) {
    throw "Refusing local staff grant: DB_HOST looks non-local ($dbHost)."
}

$dbName = Get-EnvValue $envValues 'DB_DATABASE' 'osu'
$dbUser = Get-EnvValue $envValues 'DB_USERNAME' 'osuweb'
$dbPassword = Get-EnvValue $envValues 'DB_PASSWORD'
if ([string]::IsNullOrWhiteSpace($dbPassword)) {
    throw 'Refusing local staff grant: DB_PASSWORD is missing from .env.'
}

$usernameClean = $Username.Trim().ToLowerInvariant()
if ($usernameClean -eq '') {
    throw 'Username must not be empty.'
}

$quotedUsername = Sql-String $usernameClean
$sql = @"
SET @user_id := (SELECT user_id FROM phpbb_users WHERE username_clean = $quotedUsername LIMIT 1);
SET @admin_group_id := (SELECT group_id FROM phpbb_groups WHERE identifier = 'admin' LIMIT 1);
SET @dev_group_id := (SELECT group_id FROM phpbb_groups WHERE identifier = 'dev' LIMIT 1);

INSERT INTO phpbb_user_group (group_id, user_id, group_leader, user_pending)
SELECT group_id, @user_id, 0, 0
FROM phpbb_groups
WHERE identifier IN ('admin', 'dev')
  AND @user_id IS NOT NULL
  AND group_id IS NOT NULL
ON DUPLICATE KEY UPDATE user_pending = 0;

UPDATE phpbb_users
SET group_id = COALESCE(@admin_group_id, group_id)
WHERE user_id = @user_id;

SELECT @user_id AS user_id, @admin_group_id AS admin_group_id, @dev_group_id AS dev_group_id;
"@

$sql | & docker compose exec -T `
    -e "MYSQL_PWD=$dbPassword" `
    db mysql --user=$dbUser $dbName

if ($LASTEXITCODE -ne 0) {
    throw 'Local admin/dev grant failed.'
}

Write-Host "Granted local admin/dev groups to username_clean=$usernameClean when that user exists."
