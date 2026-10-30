# Diagnóstico técnico para publicación de SICOVI

Revisión del 30/10/2026. Repositorio oficial: `https://github.com/usser-manuelmonzon/sicovi.com`; base comprobada mediante `git fetch upstream`: `7ca5c78578a94f0d4ef3b957ab756335ecba5f52` (PR #6). Se creó `codex/despliegue-render-supabase` desde esa referencia sin modificar la rama local anterior. `origin` es el fork `Manuel-SistemaSOL/sicovi.com`; `upstream` es el repositorio oficial.

## Estado encontrado y decisiones

| Área | Hallazgo real | Preparación realizada |
|---|---|---|
| Módulos | Compras, ventas, clientes, proveedores, inventario, ajustes, reportes y administración ya integrados | Se preservan controladores, modelos, rutas funcionales y servicios existentes |
| PHP | `composer.json` declaraba ^8.3, pero el lock con Symfony 8.1 ya necesitaba >=8.4.1 | Declaración corregida a ^8.4.1; imagen PHP 8.4-FPM probada en Linux |
| Seguridad Composer | `symfony/mime` 8.1.7 afectado por CVE-2026-45070 | Actualización limitada a 8.1.10; no se actualizaron las demás versiones |
| Frontend | Locks npm y Vite existentes; imágenes en public/images con nombres sensibles a mayúsculas en Linux | `npm ci` y build en etapa Node 22; imágenes y CSS/JS comprobados por HTTP |
| Contenedor | No había Dockerfile, Nginx ni arranque de producción | Construcción multietapa; PHP-FPM/Nginx supervisados, usuario sin privilegios y PORT de Render |
| Datos privados | `.env`, `.env.testing`, runtime PostgreSQL y dependencias locales presentes en Windows | Contexto Docker por allowlist; exclusión comprobada dentro de la imagen |
| PostgreSQL | Migraciones con pg_catalog.pg_c_utf8, PL/pgSQL, índices parciales/expresiones, secuencias, checks y triggers diferibles | Requisito 17+ UTF8 comprobado antes de migrar; sin modificaciones a migraciones históricas |
| Esquema | Varias migraciones y funciones se refieren específicamente a sicovi | SQL de preparación dedicado a Supabase y rol propietario sicovi_app |
| Conexión | Ya existían DB_SCHEMA y DB_SSLMODE | Se conservan; se añade CA opcional DB_SSLROOTCERT para verify-full |
| Proxies/TLS web | No había configuración explícita para el proxy Render | TRUSTED_PROXIES por configuración cacheable; se confía en For/Port/Proto y no en Forwarded-Host |
| Sesiones | Tabla sessions y cifrado configurables ya disponibles | Sesiones database con cookie Secure/HttpOnly/Lax y clave estable; recreación del contenedor comprobada |
| Caché | No existían tablas cache/cache_locks | Nueva migración técnica independiente y CACHE_STORE=database; límites de acceso compartidos entre procesos |
| Colas | No se encontraron trabajos de negocio que requieran un worker permanente | QUEUE_CONNECTION=sync; no se agrega infraestructura de workers sin necesidad |
| Archivos | No se encontraron cargas operativas mediante Storage; imágenes versionadas | Se incluyen en la imagen; futuras cargas necesitarán persistencia externa |
| Demostración | DemoSeeder era local/testing y solo cubría catálogos sin operaciones | Se mantiene intacto; nuevo CloudDemoSeeder explícito con servicios de negocio y protección de datos existentes |
| Administrador | Existía el comando seguro oficial | Se conserva y prueba en el contenedor; no se agregan endpoints ni claves universales |
| Operación | Scripts PowerShell dependen del runtime portable de esta PC | Producción utiliza shell POSIX y extensiones Linux; procedimiento Windows mediante Docker documentado |

El parche de MIME corresponde al [aviso oficial de Symfony](https://symfony.com/cve-2026-45070). Los límites de plataforma y procedimientos se contrastaron con fuentes oficiales enlazadas en la guía, sin depender de ejemplos que migren en cada reinicio.

## Alcance de los cambios

No hay cambios en `app/Services`, `app/Http/Controllers`, `app/Models`, las rutas de negocio ni las migraciones históricas respecto del commit base. No se reconstruye el sistema ni se alteran las reglas de compras, ventas, stock o auditoría. Los servicios existentes ejecutan los movimientos ficticios de manera transaccional.

Las adiciones de aplicación se limitan a dos comandos de mantenimiento, una comprobación previa, un seeder opcional y la migración técnica de caché. `bootstrap/app.php` incorpora configuración de proxy; `config/database.php` admite la CA. No se sobrescribieron `.env`, `.env.testing` ni APP_KEY locales.

No se ejecutaron migraciones sobre `sicovi_local` ni una instancia externa. Las pruebas utilizaron exclusivamente `sicovi_test`, tanto en el PostgreSQL local protegido como en el servidor Docker descartable. No se crearon cuentas de Render/Supabase ni se solicitaron o almacenaron sus credenciales.

## Inventario de archivos

| Archivo o grupo | Propósito |
|---|---|
| Dockerfile, .dockerignore | Imagen reproducible desde locks y exclusión de información local |
| docker/entrypoint.sh | Validación y arranque; sin migraciones/seeders/key:generate |
| docker/validate-environment.php | Rechazo de configuración insegura o incompleta sin imprimir secretos |
| docker/nginx.conf.template | public como raíz, PORT dinámico, temporales sin root y logs sin querystrings |
| docker/php.ini, docker/php-fpm.conf | Extensiones/configuración PHP, errores a stderr y límites de procesos |
| docker/supervisord.conf | Supervisión y señales de parada de Nginx/PHP-FPM |
| render.yaml | Web Service Docker, health check, variables no secretas y publicación manual |
| .env.production.example | Plantilla sin credenciales reales |
| .gitignore, .gitattributes | Inclusión de la plantilla pública y finales de línea LF para Linux |
| composer.json, composer.lock | Mínimo PHP coherente y parche aislado de MIME |
| config/database.php, config/trustedproxy.php, bootstrap/app.php | CA y proxies compatibles con caché de configuración |
| app/Support/DeploymentPreflight.php | Versión/collation/permisos/tablas y conciliación de stock |
| app/Console/Commands/PrepareDeployment.php | Procedimiento controlado e idempotente de migraciones/SystemSeeder |
| database/migrations/2026_10_30_140000_create_deployment_cache_tables.php | Caché y bloqueos persistentes en PostgreSQL |
| database/seeders/CloudDemoSeeder.php | Datos ficticios completos, transaccionales y sin sobrescribir información |
| app/Console/Commands/LoadCloudDemo.php | Autorización explícita del lote mediante administrador real |
| scripts/postgresql/supabase.sql | Esquema privado/propiedad/privilegios mínimos, sin contraseñas |
| compose.verificacion.yaml, scripts/cloud/*, scripts/verificar-despliegue.ps1 | Validación local aislada con TLS y recreación del contenedor |
| tests/Feature/CloudDeploymentTest.php, ProductionProxyTest.php | Idempotencia, conservación, permisos, rollback, caché y enlaces HTTPS |
| tests/Unit/ProductionEnvironmentTest.php | Variables seguras y ausencia de secretos en la salida del validador |
| README.md y docs/DESPLIEGUE_RENDER_SUPABASE.md | Entrada al procedimiento y guía paso a paso |
| docs/PROPUESTA_PR_DESPLIEGUE.md | Propuesta para revisión; no es un PR publicado |
| docs/verificacion/despliegue-2026-10-30/* | Resultados ejecutados y reporte JUnit |

## Límites de la verificación

El servidor PostgreSQL Docker reproduce versión, UTF8, rol propietario no superusuario, esquema, TLS y ejecución de las migraciones. No reproduce todos los controles administrados ni la red de un proyecto Supabase real. El proxy HTTPS se comprueba mediante cabeceras locales; el certificado público y el dominio Render se validarán al autorizar el primer despliegue.

No se ejecutaron pruebas de carga prolongada ni una restauración real de respaldo. Las capacidades del plan, conectividad, certificados CA, usuarios y permisos del proyecto externo quedan pendientes de su configuración por el titular.

La imagen usa etiquetas oficiales de las ramas PHP 8.4, Node 22 y Composer 2 para recibir correcciones. Los locks fijan las bibliotecas; los digest exactos de las imágenes base pueden cambiar al reconstruir. Para una publicación regulada o una revisión congelada puede fijarse el digest probado y mantener un calendario explícito de actualizaciones.
