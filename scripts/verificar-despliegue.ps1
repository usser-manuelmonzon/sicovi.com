param([ValidateRange(1024,65535)][int]$Port = 18080)
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
$taskProject = 'sicovi-cloud-verify-' + ([guid]::NewGuid().ToString('N').Substring(0,8))
$taskPrivateDirectory = Join-Path $taskRoot 'storage/runtime'
New-Item -ItemType Directory -Path $taskPrivateDirectory -Force | Out-Null
$taskEnvironmentFile = Join-Path $taskPrivateDirectory ($taskProject + '.env')
$taskSessionFile = Join-Path $taskPrivateDirectory ($taskProject + '.session.json')
function New-VerificationSecret {
    $taskBytes = [byte[]]::new(32)
    $taskGenerator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $taskGenerator.GetBytes($taskBytes); return [Convert]::ToBase64String($taskBytes) }
    finally { $taskGenerator.Dispose() }
}
$taskValues = @(
    ('VERIFY_POSTGRES_PASSWORD=' + (New-VerificationSecret)),
    ('VERIFY_APP_PASSWORD=' + (New-VerificationSecret)),
    ('VERIFY_APP_KEY=base64:' + (New-VerificationSecret)),
    ('VERIFY_PORT=' + $Port)
)
[IO.File]::WriteAllLines($taskEnvironmentFile, $taskValues, [Text.UTF8Encoding]::new($false))
$taskCompose = @('compose', '-p', $taskProject, '--env-file', $taskEnvironmentFile, '-f', (Join-Path $taskRoot 'compose.verificacion.yaml'))
function Invoke-VerificationDocker {
    param([string[]]$DockerArguments)
    & docker @DockerArguments
    if ($LASTEXITCODE -ne 0) { throw 'Falló una operación Docker de verificación; revise su salida sin publicar archivos privados.' }
}
try {
    Invoke-VerificationDocker -DockerArguments ($taskCompose + @('config', '--quiet'))
    Invoke-VerificationDocker -DockerArguments ($taskCompose + @('up', '-d', '--wait', '--wait-timeout', '120', '--no-build'))
    Invoke-VerificationDocker -DockerArguments ($taskCompose + @('exec', '-T', 'app', 'php', 'artisan', 'sicovi:preparar-despliegue', '--aplicar'))
    $taskAppId = (& docker @taskCompose ps -q app).Trim()
    if ($LASTEXITCODE -ne 0 -or !$taskAppId) { throw 'No se identificó el contenedor propio de verificación.' }
    Invoke-VerificationDocker -DockerArguments @('cp', (Join-Path $PSScriptRoot 'cloud/smoke.php'), "${taskAppId}:/tmp/sicovi-smoke.php")
    Invoke-VerificationDocker -DockerArguments ($taskCompose + @('exec', '-T', 'app', 'php', '/tmp/sicovi-smoke.php'))
    Invoke-VerificationDocker -DockerArguments @('cp', "${taskAppId}:/tmp/sicovi-cloud-session.json", $taskSessionFile)
    Invoke-VerificationDocker -DockerArguments ($taskCompose + @('up', '-d', '--wait', '--wait-timeout', '120', '--force-recreate', '--no-deps', 'app'))
    $taskAppId = (& docker @taskCompose ps -q app).Trim()
    if ($LASTEXITCODE -ne 0 -or !$taskAppId) { throw 'No se identificó el nuevo contenedor propio.' }
    Invoke-VerificationDocker -DockerArguments @('cp', (Join-Path $PSScriptRoot 'cloud/smoke.php'), "${taskAppId}:/tmp/sicovi-smoke.php")
    Invoke-VerificationDocker -DockerArguments @('cp', $taskSessionFile, "${taskAppId}:/tmp/sicovi-cloud-session.json")
    Invoke-VerificationDocker -DockerArguments ($taskCompose + @('exec', '-T', 'app', 'php', '/tmp/sicovi-smoke.php', '--session-only'))
    Write-Output 'Verificación completa: contenedores Linux, TLS PostgreSQL, módulos, CSRF y sesión/caché tras recreación.'
} catch {
    & docker @taskCompose logs --no-color --tail 65 app
    throw
} finally {
    # Only this freshly generated project is removed; PostgreSQL data exists in tmpfs.
    & docker @taskCompose down --volumes --remove-orphans
    if (Test-Path -LiteralPath $taskEnvironmentFile) { Remove-Item -LiteralPath $taskEnvironmentFile }
    if (Test-Path -LiteralPath $taskSessionFile) { Remove-Item -LiteralPath $taskSessionFile }
}
