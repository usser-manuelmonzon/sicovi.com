<?php

// Runtime-only validation: never print credentials or generate/change APP_KEY.
$errors = [];
$get = static fn (string $key): string => (string) (getenv($key) === false ? '' : getenv($key));
$isTrue = static fn (string $key): bool => in_array(strtolower($get($key)), ['1', 'true', '(true)'], true);
foreach (['APP_KEY', 'APP_URL', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $required) {
    if ($get($required) === '' || str_contains($get($required), 'CONFIGURAR')) {
        $errors[] = "Configure {$required} en las variables privadas del servicio.";
    }
}
if ($get('APP_ENV') !== 'production' || ! in_array(strtolower($get('APP_DEBUG')), ['false', '0', '(false)'], true)) {
    $errors[] = 'El servicio requiere APP_ENV=production y APP_DEBUG=false.';
}
$key = $get('APP_KEY');
$decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
if ($decoded === false || strlen($decoded) !== 32) {
    $errors[] = 'APP_KEY debe ser una clave Laravel AES-256 válida; conserve la existente.';
}
if (! filter_var($get('APP_URL'), FILTER_VALIDATE_URL) || parse_url($get('APP_URL'), PHP_URL_SCHEME) !== 'https') {
    $errors[] = 'APP_URL debe ser la URL HTTPS pública del servicio.';
}
if ($get('DB_CONNECTION') !== 'pgsql' || $get('DB_SCHEMA') !== 'sicovi' || $get('DB_URL') !== '') {
    $errors[] = 'Use DB_CONNECTION=pgsql, DB_SCHEMA=sicovi y variables DB_* separadas, sin DB_URL.';
}
if (! in_array($get('DB_SSLMODE'), ['require', 'verify-ca', 'verify-full'], true)) {
    $errors[] = 'La conexión debe exigir TLS con DB_SSLMODE=require o verify-full.';
}
if ($get('DB_SSLMODE') === 'verify-full' || $get('DB_SSLMODE') === 'verify-ca') {
    if ($get('DB_SSLROOTCERT') === '' || ! is_readable($get('DB_SSLROOTCERT'))) {
        $errors[] = 'Monte el certificado CA y configure DB_SSLROOTCERT para verificar TLS.';
    }
}
if ($get('DB_PORT') === '6543') {
    $errors[] = 'Use conexión directa o Session Pooler, no Transaction Pooler (6543).';
}
if ($get('SESSION_DRIVER') !== 'database' || ! $isTrue('SESSION_SECURE_COOKIE') || ! $isTrue('SESSION_ENCRYPT')) {
    $errors[] = 'Configure sesiones database, cifradas y con cookie segura.';
}
if ($get('CACHE_STORE') !== 'database' || $get('QUEUE_CONNECTION') !== 'sync') {
    $errors[] = 'Esta infraestructura usa CACHE_STORE=database y QUEUE_CONNECTION=sync.';
}
if ($isTrue('SICOVI_DEMO_ACCOUNTS')) {
    $errors[] = 'SICOVI_DEMO_ACCOUNTS debe permanecer false en producción.';
}
if ($get('LOG_CHANNEL') !== 'stderr' || $get('TRUSTED_PROXIES') === '') {
    $errors[] = 'Configure LOG_CHANNEL=stderr y los proxies de confianza de Render.';
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, array_unique($errors)).PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Configuración de producción validada; sin migraciones ni cambios de claves.'.PHP_EOL);
