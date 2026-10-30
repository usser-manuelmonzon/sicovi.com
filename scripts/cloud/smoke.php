<?php

use App\Support\DemoAccess;
use App\Support\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, $exception instanceof PDOException ? 'Falló una comprobación de base de datos de verificación.'.PHP_EOL : $exception->getMessage().PHP_EOL);
    exit(1);
});
app(TestDatabaseGuard::class)->assertSafe(DB::connection());
function cloudEnsure(bool $condition, string $description): void
{
    if (! $condition) {
        throw new RuntimeException($description);
    }
    echo 'OK: '.$description.PHP_EOL;
}
function cloudRequest(string $path, array &$cookies, ?array $data = null): array
{
    $headers = [];
    $curl = curl_init('http://127.0.0.1:10000'.$path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Host: sicovi.example.test', 'X-Forwarded-Proto: https', 'X-Forwarded-Port: 443'],
        CURLOPT_COOKIE => implode('; ', array_map(fn ($key, $value) => $key.'='.$value, array_keys($cookies), $cookies)),
        CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers, &$cookies): int {
            $headers[] = trim($line);
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $match)) {
                $cookies[$match[1]] = $match[2];
            }

            return strlen($line);
        },
    ]);
    if ($data !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    }
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
}

$probePath = '/tmp/sicovi-cloud-session.json';
if (in_array('--session-only', $argv, true)) {
    $cookies = json_decode(file_get_contents($probePath), true, flags: JSON_THROW_ON_ERROR);
    cloudEnsure(cloudRequest('/', $cookies)['status'] === 200, 'Sesión autenticada conservada al recrear el contenedor');
    cloudEnsure(Cache::store('database')->get('cloud-recreate-probe') === 'persistido', 'Caché conservada al recrear el contenedor');
    exit;
}
cloudEnsure((bool) DB::selectOne('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()')->ssl, 'PostgreSQL conectado con TLS');
cloudEnsure(posix_geteuid() !== 0 && ! is_writable('/var/www/html/app') && is_writable('/var/www/html/storage') && is_writable('/var/www/html/bootstrap/cache'), 'Proceso sin root y permisos limitados de escritura');
cloudEnsure(! app(DemoAccess::class)->allowed(), 'Cuentas locales inseguras bloqueadas en producción');
foreach (['.env', '.env.testing', '.git', 'storage/runtime', 'node_modules', 'public/hot'] as $excluded) {
    cloudEnsure(! file_exists('/var/www/html/'.$excluded), 'Excluido de la imagen: '.$excluded);
}
$password = bin2hex(random_bytes(12)).'!Nube1';
$command = new CommandTester(Artisan::all()['sicovi:crear-administrador']);
$command->setInputs(['Administrador de verificación', 'cloud-verify', 'cloud-verify@example.test', $password, $password]);
cloudEnsure($command->execute([]) === 0, 'Administrador real creado mediante el comando oficial');
cloudEnsure(Artisan::call('sicovi:cargar-ejemplo', ['--admin' => 'cloud-verify', '--confirmar' => true]) === 0, 'Escenario ficticio generado con servicios de negocio');
$movements = DB::table('inventory_movements')->count();
cloudEnsure(Artisan::call('sicovi:cargar-ejemplo', ['--admin' => 'cloud-verify', '--confirmar' => true]) === 0
    && $movements === DB::table('inventory_movements')->count(), 'Repetir los ejemplos no duplica movimientos');
$cookies = [];
cloudEnsure(cloudRequest('/up', $cookies)['status'] === 200, 'Endpoint /up público');
$page = cloudRequest('/login', $cookies);
cloudEnsure($page['status'] === 200 && str_contains($page['body'], 'https://sicovi.example.test/login'), 'Login y enlaces HTTPS detrás del proxy');
$sessionHeader = implode("\n", array_filter($page['headers'], fn ($line) => stripos($line, 'Set-Cookie: sicovi-session=') === 0));
cloudEnsure(str_contains(strtolower($sessionHeader), 'secure') && str_contains(strtolower($sessionHeader), 'httponly') && str_contains(strtolower($sessionHeader), 'samesite=lax'), 'Cookie Secure, HttpOnly y SameSite=Lax');
preg_match('/name="_token"[^>]+value="([^"]+)"/', $page['body'], $token);
cloudEnsure(isset($token[1]), 'Formulario protegido con token CSRF');
$anonymous = [];
cloudEnsure(cloudRequest('/login', $anonymous, ['username' => 'cloud-verify', 'password' => $password])['status'] === 419, 'CSRF rechaza un POST sin token');
cloudEnsure(cloudRequest('/login', $cookies, ['username' => 'cloud-verify', 'password' => $password, '_token' => $token[1]])['status'] === 302, 'Inicio de sesión por usuario y contraseña');
foreach (['/', '/products', '/suppliers', '/customers', '/purchases', '/sales', '/inventory', '/inventory/adjustments', '/reports', '/users', '/settings'] as $path) {
    cloudEnsure(cloudRequest($path, $cookies)['status'] === 200, 'Módulo accesible: '.$path);
}
$payload = DB::table('sessions')->whereNotNull('user_id')->value('payload');
$plain = app('encrypter')->decrypt(base64_decode($payload));
cloudEnsure(is_array(json_decode($plain, true)), 'Sesión cifrada en PostgreSQL y descifrada con APP_KEY estable');
$manifest = json_decode(file_get_contents('/var/www/html/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
    cloudEnsure(cloudRequest('/build/'.$manifest[$entry]['file'], $cookies)['status'] === 200, 'Recurso Vite servido: '.$entry);
}
foreach (['loginDesktop.png', 'loginCelular.png'] as $image) {
    cloudEnsure(cloudRequest('/images/'.$image, $cookies)['status'] === 200, 'Imagen servida en Linux: '.$image);
}
cloudEnsure(in_array(cloudRequest('/.env', $cookies)['status'], [403, 404], true), 'Archivo de entorno inaccesible por HTTP');
Cache::store('database')->put('cloud-recreate-probe', 'persistido', 300);
file_put_contents($probePath, json_encode($cookies, JSON_THROW_ON_ERROR));
echo 'Verificación HTTP/TLS PostgreSQL terminada; sin conexión a Render ni Supabase.'.PHP_EOL;
