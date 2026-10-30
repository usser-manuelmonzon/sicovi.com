# Resultados ejecutados: preparación cloud de SICOVI

Fecha: 30/10/2026, America/Guatemala. Base oficial `7ca5c78578a94f0d4ef3b957ab756335ecba5f52`. Rama `codex/despliegue-render-supabase`.

## Resultado final

| Comprobación | Resultado observado |
|---|---|
| PHPUnit completo | **249 pruebas, 1,571 aserciones, 0 fallos**, sin avisos en la ejecución final; 36.857 s, PHP 8.4.12 y PostgreSQL 17 local |
| Pruebas JavaScript | **16 correctas, 0 fallos**, `node --test tests/Frontend/login.test.js` |
| Composer validate --strict | Correcto |
| Sintaxis PHP | Correcta en 137 archivos de aplicación, configuración, migraciones, comandos y pruebas |
| Formato PHP | Pint aplicado únicamente a archivos nuevos de infraestructura y sus pruebas |
| Composer check-platform-reqs --no-dev | Correcto en Windows y en la construcción Linux |
| Composer audit --locked --no-dev | 0 avisos tras el parche symfony/mime 8.1.7 → 8.1.10 |
| npm audit --omit=dev | 0 vulnerabilidades reportadas en dependencias de producción |
| Docker build | Correcto; npm ci, composer install desde locks y compilación Vite completados |
| Runtime Linux | PHP **8.4.27**, usuario **33 (www-data)**, PostgreSQL **17.11**, SSL real activo entre PHP y PostgreSQL |
| Extensiones | pdo_pgsql, pgsql, bcmath, mbstring, xml, dom, intl, zip y OPcache disponibles |
| Render Blueprint | render.yaml validado localmente con el JSON Schema oficial `https://render.com/schema/render.yaml.json` |
| Migraciones | Ejecutadas en el PostgreSQL Docker descartable como sicovi_app, sin superusuario |
| Variables incompletas | Arranque rechazado con salida 1, sin mostrar claves o contraseñas |
| Salud HTTP | /up responde 200 |
| HTTPS detrás de proxy | URLs HTTPS y cookies Secure/HttpOnly/SameSite=Lax verificadas |
| CSRF | POST sin token rechazado con 419; POST correcto autentica |
| Administrador | Creado mediante el comando oficial con entrada de prueba y contraseña transitoria aleatoria |
| Módulos HTTP | Inicio, productos, proveedores, clientes, compras, ventas, inventario, ajustes, reportes, usuarios y configuración responden 200 tras autenticar |
| Recursos | CSS/JS del manifiesto Vite y loginDesktop.png/loginCelular.png responden 200 en Linux |
| Datos ficticios | Confirmaciones, reversiones y ajustes mediante servicios; repetir no duplica movimientos |
| Sesión cifrada | Payload de PostgreSQL descifrado y verificado con la clave estable de la instancia descartable |
| Recreación | Sesión autenticada y caché conservadas después de reemplazar completamente el contenedor web |
| Imagen y secretos | Ausentes .env, .env.testing, .git, storage/runtime, node_modules y public/hot; /.env inaccesible por HTTP |
| Permisos | Aplicación no root; código no escribible; storage y bootstrap/cache escribibles |
| Limpieza | Contenedores y red del proyecto aleatorio de verificación retirados; claves/cookies transitorias locales eliminadas |

La suite utiliza la guarda de base existente antes de `RefreshDatabase`. No se ejecutó migración de aplicación ni carga ficticia sobre `sicovi_local`. Los módulos funcionales y las migraciones históricas permanecen sin cambios respecto de la base oficial. `.env` y `.env.testing` conservan sus fechas anteriores a esta tarea.

## Evidencia y repetición

El reporte estructurado de la ejecución final está en [phpunit.xml](phpunit.xml). Las comprobaciones satisfactorias del contenedor se conservaron en [docker-smoke.txt](docker-smoke.txt), sin claves, cookies ni contraseñas. La comprobación Docker completa se reproduce con:

```powershell
docker build -t sicovi-cloud:verificacion .
.\scripts\verificar-despliegue.ps1
```

El verificador genera una base `sicovi_test` aislada en memoria y un proyecto Compose con nombre aleatorio. Sus archivos de entorno y sesión se guardan temporalmente bajo storage/runtime y se eliminan al finalizar. La prueba del administrador usa CommandTester de Symfony para suministrar la entrada al comando oficial; no crea una contraseña fija de producción.

La prueba de PostgreSQL usa TLS con un certificado autofirmado exclusivo de esa instancia efímera y `sslmode=require`. La validación de nombre con una CA real de Supabase (`verify-full`) queda pendiente de la configuración externa. El tráfico HTTP local simula el proxy HTTPS con cabeceras; no demuestra por sí mismo un certificado HTTPS público de Render.

## Incidencias resueltas durante la verificación

- PostgreSQL local estaba detenido: el primer intento no pudo conectar y no ejecutó cambios. Se inició el runtime existente y se repitió la suite sobre sicovi_test.
- La primera configuración del proxy consultaba config antes de inicializar Artisan. Se movió el valor a config/trustedproxy.php, leído por el middleware al procesar la petición.
- La prueba sin privilegios detectó dos rutas temporales predeterminadas de Nginx fuera de /tmp. Se configuraron también uwsgi/scgi en /tmp.
- Se ajustó el verificador al formato real de serialización/cifrado de Laravel; se confirmó el acceso conservado después de recrear el contenedor.
- El aviso inicial de PHPUnit sobre un mock sin expectativa se corrigió; el resultado final no presenta avisos.
- Los avisos existentes de Vite sobre rutas /images no impidieron el build: son imágenes públicas copiadas en la imagen y verificadas con respuestas HTTP 200.

## Pendiente en cuentas externas

No se validaron una suscripción específica, la conexión real a Supabase, el pooler de un proyecto concreto, su CA, el dominio/certificado Render, un despliegue público, cargas prolongadas o una restauración real de respaldo. Tampoco hubo push, merge ni PR remoto. La guía identifica esas acciones para el titular.
