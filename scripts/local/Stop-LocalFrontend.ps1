[CmdletBinding()]
param(
    [switch]$Down,
    [switch]$DestroyVolumes
)

. "$PSScriptRoot\_common.ps1"

Enter-RepoRoot

if ($DestroyVolumes) {
    if (-not $Down) {
        throw 'Use -Down together with -DestroyVolumes so destructive volume deletion is explicit.'
    }

    docker compose down --volumes
    exit $LASTEXITCODE
}

if ($Down) {
    docker compose down
    exit $LASTEXITCODE
}

docker compose stop
exit $LASTEXITCODE
