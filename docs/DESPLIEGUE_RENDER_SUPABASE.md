# SICOVI: Render con PostgreSQL de Supabase

Preparación del 30/10/2026. Esta guía acompaña los archivos de infraestructura; no significa que el sistema ya esté publicado. La preparación local parte del commit oficial `7ca5c78578a94f0d4ef3b957ab756335ecba5f52`, en la rama `codex/despliegue-render-supabase`. No se realizó push, merge, alta de servicios ni despliegue externo.

## 1. Qué se va a publicar

Render ejecutará un contenedor Linux con PHP 8.4, PHP-FPM y Nginx. Supabase conservará los datos, las sesiones cifradas y la caché de Laravel. La aplicación mantiene sus usuarios, permisos, auditoría y servicios de compras, ventas e inventario. No utiliza Supabase Auth ni las claves de su Data API.

Los recursos Vite se compilan durante la construcción. El arranque valida variables, prepara las cachés de código y escucha en `0.0.0.0:$PORT`, sin ejecutar migraciones, seeders ni generación de claves. Solo `storage/` y `bootstrap/cache/` son escribibles por el proceso; el servidor no se ejecuta como root.

Primero revise los cambios y autorice su publicación en GitHub. Render necesita una rama que contenga estos archivos; hasta entonces no puede construir esta preparación desde el repositorio remoto. `render.yaml` apunta a `manuel-base-acceso-catalogos`, para utilizarla después de la integración aprobada. Para una revisión previa puede elegir la rama de infraestructura una vez autorizada y publicada.

## 2. Requisitos locales

- Docker Desktop funcionando con contenedores Linux.
- Git y PowerShell. Los comandos siguientes parten de `C:\xampp\htdocs\sicovi.com`.
- Acceso propio a GitHub, Render y Supabase.
- Para herramientas PHP fuera de Docker: PHP **8.4.1 o superior de la rama 8.4**, extensiones del proyecto y Composer. El lock ya exigía ese mínimo; `composer.json` ahora lo declara expresamente.

Construya la misma imagen que usará Render:

```powershell
Set-Location C:\xampp\htdocs\sicovi.com
docker version
docker build -t sicovi-cloud:local .
```

Para comprobar todo contra una base local descartable independiente:

```powershell
docker build -t sicovi-cloud:verificacion .
.\scripts\verificar-despliegue.ps1
```

El verificador crea un proyecto Compose con nombre aleatorio, PostgreSQL 17 con TLS, una base `sicovi_test`, claves transitorias y un puerto HTTP limitado a `127.0.0.1:18080`. Retira exclusivamente esos contenedores y sus archivos temporales al finalizar. No conecta con `sicovi_local` ni con Supabase. Si el puerto está ocupado, use `-Port 18081`. Los datos PostgreSQL de esa verificación viven en `tmpfs`.

## 3. Cuenta y proyecto de Supabase

1. Regístrese o inicie sesión en [Supabase](https://supabase.com/dashboard).
2. Cree una organización propia si todavía no la tiene y luego un proyecto para SICOVI.
3. Elija una región cercana a la que usará en Render.
4. Genere y conserve una contraseña fuerte para el administrador PostgreSQL. Esta contraseña no es la de acceso al sistema SICOVI.
5. Espere a que el proyecto termine de inicializarse.
6. Abra SQL Editor y ejecute la comprobación siguiente, sin modificar tablas:

```sql
SELECT version(), current_setting('server_version_num') AS version_numerica,
       current_setting('server_encoding') AS codificacion,
       to_regcollation('pg_catalog.pg_c_utf8') AS collation_requerida;
```

Se necesita PostgreSQL **17 o posterior**, UTF8 y una collation no nula. La verificación realizada para esta entrega usa PostgreSQL 17. No debe suponerse la versión de un proyecto existente de Supabase. La disponibilidad de Postgres 17 está documentada en su [guía de actualización](https://supabase.com/docs/guides/platform/upgrading).

Si aparece una versión inferior o falta la collation, deténgase. Cree un proyecto compatible o planifique su actualización con respaldo mediante Supabase. Las migraciones históricas no se han modificado. La collation conserva reglas Unicode específicas que no deben sustituirse por `C`, ICU o la collation predeterminada. Véase [PostgreSQL 17: collations](https://www.postgresql.org/docs/17/collation.html).

## 4. Rol PostgreSQL y esquema privado

La conexión de Render debe usar un rol dedicado `sicovi_app`, no la contraseña del administrador general del proyecto. En Database > Roles cree un rol con LOGIN y contraseña fuerte propia, sin superusuario, creación de bases, creación de roles, replicación o bypass de RLS. Si su interfaz no permite hacerlo, el administrador del proyecto puede crear ese LOGIN desde SQL Editor usando una contraseña privada; no guarde esa sentencia con la contraseña en Git ni en esta guía. Supabase explica los [roles y usuarios PostgreSQL](https://supabase.com/docs/guides/database/postgres/roles).

En SQL Editor, con el rol `postgres`, ejecute el contenido de:

[`scripts/postgresql/supabase.sql`](../scripts/postgresql/supabase.sql)

El script comprueba versión/collation, exige que exista el LOGIN, crea el esquema `sicovi` con propietario `sicovi_app` y restringe el acceso de los roles API. Si el esquema ya tiene otro propietario, se detiene: no transfiere propiedad ni elimina objetos. El rol de SICOVI podrá crear y administrar sus tablas, secuencias, funciones y triggers en ese esquema, sin privilegios globales de administrador.

No añada `sicovi` a los esquemas expuestos por Data API. Esta instalación usa autorización de Laravel por PDO; no debe abrir sus tablas a `anon` o `authenticated` ni crear políticas de acceso público para solucionar errores. Tampoco necesita modificar los esquemas internos `auth`, `storage` o `extensions` de Supabase.

El nombre de la base de un proyecto Supabase normalmente es `postgres`; **no se crea una base llamada sicovi**. `sicovi` es el esquema interno. No ejecute el antiguo `scripts/postgresql/preparar.psql` en Supabase: ese archivo crea bases y roles para el entorno local.

## 5. Obtener la conexión correcta

Abra **Connect** en el proyecto Supabase y copie los datos exactos del método elegido. No deduzca el host a partir de la región.

| Método | Cuándo utilizarlo | Puerto y usuario |
|---|---|---|
| Direct connection | Si el entorno de Render alcanza la dirección del proyecto por IPv6, o dispone de IPv4 contratado | Habitualmente 5432; usuario `sicovi_app` |
| Session pooler | Opción práctica para un backend persistente que necesita conectividad IPv4 | 5432; usuario `sicovi_app.REFERENCIA_DEL_PROYECTO` |
| Transaction pooler | No utilizar para esta configuración | Habitualmente 6543 |

SICOVI configura `search_path` por sesión y el procedimiento de despliegue usa un bloqueo de sesión. El modo transacción no conserva ese estado; tampoco se han deshabilitado prepared statements para adaptarlo. La elección se basa en la [documentación de conexión de Supabase](https://supabase.com/docs/guides/database/connecting-to-postgres).

Use `DB_SSLMODE=require` como mínimo. Para verificar también la identidad del servidor, descargue la CA desde Database > Settings > SSL Configuration, súbala como Secret File en Render y use `DB_SSLMODE=verify-full` más `DB_SSLROOTCERT=/etc/secrets/NOMBRE_REAL_DEL_ARCHIVO`. El modo `require` cifra la conexión pero no verifica el nombre del servidor. Active SSL Enforcement cuando esté disponible y revise las [recomendaciones de producción](https://supabase.com/docs/guides/deployment/going-into-prod).

## 6. Preparar las variables privadas

La plantilla pública es [`.env.production.example`](../.env.production.example). No contiene credenciales funcionales. Mantenga `.env`, `.env.testing` y las claves de la instalación local como están.

Para ejecutar la preparación remota desde Windows, cree una copia privada aparte:

```powershell
New-Item -ItemType Directory -Path storage/runtime -Force | Out-Null
if (Test-Path storage/runtime/render.produccion.env) { throw 'El archivo privado ya existe; edítelo sin sobrescribirlo.' }
Copy-Item .env.production.example storage/runtime/render.produccion.env
notepad storage/runtime/render.produccion.env
```

Complete los marcadores `CONFIGURAR`. Si todavía no conoce la URL asignada por Render, puede posponer únicamente `APP_URL` para los comandos CLI de preparación; el arranque HTTP exigirá la URL HTTPS real. Actualícela en el archivo privado y en Render al crear el servicio. Ese archivo está excluido de Git y del contexto Docker. No lo adjunte a incidencias, capturas o mensajes. Las mismas variables se colocarán en **Environment** de Render, que dispone de [variables privadas y Secret Files](https://render.com/docs/configure-environment-variables).

| Variable | Valor o criterio |
|---|---|
| `APP_NAME` | `SICOVI` |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_KEY` | Clave Laravel estable; conservarla entre despliegues |
| `APP_URL` | URL pública HTTPS final del servicio, sin rutas adicionales |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `es` / `es` |
| `DISPLAY_TIMEZONE` | `America/Guatemala`; los registros se conservan en UTC |
| `TRUSTED_PROXIES` | `*` solo en el servicio detrás de la entrada controlada de Render; puede restringirse a IP/CIDR conocidos separados por comas |
| `DB_CONNECTION` / `DB_SCHEMA` | `pgsql` / `sicovi` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE` | Copiar de Connect; normalmente puerto 5432 y base `postgres` |
| `DB_USERNAME`, `DB_PASSWORD` | Rol dedicado y contraseña privada; sufijo de referencia si usa Session Pooler |
| `DB_SSLMODE` | `require`, o preferiblemente `verify-full` con CA configurada |
| `DB_SSLROOTCERT` | Solo si verifica la CA: ruta real del Secret File |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` / `SESSION_ENCRYPT` | `true` / `true` |
| `SESSION_HTTP_ONLY` / `SESSION_SAME_SITE` | `true` / `lax` |
| `SESSION_COOKIE` / `SESSION_LIFETIME` | `sicovi-session` / `120` minutos |
| `CACHE_STORE` | `database`, con las nuevas tablas técnicas de caché |
| `CACHE_PREFIX` | Prefijo exclusivo de esta instalación; no cambiarlo para evadir el límite de intentos |
| `QUEUE_CONNECTION` | `sync`; esta infraestructura no inicia workers separados |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stderr` / `warning` |
| `MAIL_MAILER` | `log`; no configura envío de correos reales |
| `SICOVI_DEMO_ACCOUNTS` | `false` |
| `PORT` | Render lo proporciona; predeterminado del contenedor: 10000 |

No configure `DB_URL` ni `DATABASE_URL`: esta preparación emplea variables separadas para evitar precedencias inesperadas. Escriba las contraseñas tal como son, sin percent-encoding propio de una URL.

### APP_KEY

Si se continúa una instalación existente, copie su clave de forma privada y consérvela. Solo para una instalación **nueva e independiente**, puede generar una clave una vez, sin sobrescribir ningún archivo:

```powershell
docker run --rm sicovi-cloud:local php artisan key:generate --show
```

Guarde el resultado directamente en el archivo privado y en Render. No lo publique. El contenedor no ejecuta `key:generate` al arrancar. Perder o cambiar la clave impide descifrar las sesiones existentes.

## 7. Inicializar Supabase de forma controlada

Después de preparar el esquema y las variables, compruebe el destino desde Docker local:

```powershell
docker run --rm --env-file storage/runtime/render.produccion.env sicovi-cloud:local php artisan sicovi:preparar-despliegue
```

Ese comando realiza comprobaciones de lectura. Verifica versión, UTF8, collation, esquema y permisos. Si hay tablas, comprueba las tablas de los módulos y la conciliación entre saldos y movimientos. En una instalación anterior a esta entrega puede informar que falta `cache`: el paso explícito siguiente aplica esa migración técnica.

Para una base nueva, o una actualización revisada y respaldada:

```powershell
docker run --rm --env-file storage/runtime/render.produccion.env sicovi-cloud:local php artisan sicovi:preparar-despliegue --aplicar
```

El comando obtiene un bloqueo, ejecuta únicamente migraciones pendientes con `migrate --force`, carga `SystemSeeder` y verifica la integridad. Puede repetirse: no reinicia información, no restaura permisos previamente revocados, no crea contraseñas ni carga ejemplos. Si otro despliegue mantiene el bloqueo, se detiene en vez de ejecutar ambos a la vez.

No lo sustituya por `migrate:fresh`, `db:wipe`, rollback masivo ni truncados. Una interrupción puede dejar migraciones anteriores completadas: revise `migrate:status`, corrija la causa y repita el procedimiento; no elimine filas del historial para ocultarlo.

En un plan de Render con Pre-deploy Command puede configurar, tras revisar el procedimiento, `php artisan sicovi:preparar-despliegue --aplicar`. No está activado por defecto. En Free use los comandos Docker locales anteriores para inicializar/actualizar Supabase antes de publicar el nuevo contenedor. Render distingue [pre-deploy de arranque y su disponibilidad por plan](https://render.com/docs/deploys#pre-deploy-command).

## 8. Crear el Administrador real

```powershell
docker run --rm -it --env-file storage/runtime/render.produccion.env sicovi-cloud:local php artisan sicovi:crear-administrador
```

Ingrese nombre, usuario corto, correo de contacto y una contraseña personal fuerte con confirmación. La contraseña se solicita oculta; no se escribe en parámetros, seeders o variables públicas. Se requieren al menos 12 caracteres, mayúscula, minúscula, número y símbolo. No hay una contraseña universal de producción. Repetir el comando con un usuario o correo existentes no sobrescribe la cuenta.

El acceso web utiliza **usuario y contraseña**. El correo es un dato de la cuenta. El administrador creado es una cuenta normal, no `is_demo`. Los accesos locales de demostración continúan bloqueados en producción, incluso si se habilitara por error su bandera.

En un plan con Shell también puede ejecutar el mismo comando desde la consola privada de Render, sin crear una ruta web temporal para registrar administradores.

## 9. Datos ficticios opcionales

Para una instalación de exhibición vacía, después de crear el administrador y **antes de registrar productos u operaciones reales**:

```powershell
docker run --rm --env-file storage/runtime/render.produccion.env sicovi-cloud:local php artisan sicovi:cargar-ejemplo --admin=USUARIO_REAL --confirmar
```

`CloudDemoSeeder` está separado de `SystemSeeder` y se ejecuta solo mediante ese procedimiento explícito. No se habilitan los usuarios demo locales ni se modifica su protección. El administrador real debe conservar los permisos de las operaciones que autoriza.

El escenario crea catálogos, presentaciones y cuatro productos en GTQ, dos proveedores, tres clientes, cuatro compras, cinco ventas y tres ajustes, con borradores, confirmaciones, anulaciones y un borrador cancelado. Los nombres llevan `[DEMO]`, los correos usan el dominio reservado `.invalid` y no se usan identificaciones tributarias reales. Las fechas permiten consultar las operaciones en los últimos 30 días.

Los servicios existentes confirman compras/ventas y realizan ajustes/reversiones. No se insertan movimientos ni saldos operativos a mano. El lote es transaccional y deja un marcador de idempotencia. Repetirlo no duplica movimientos ni modifica los registros. Si ya existen catálogos u operaciones y no es el mismo lote completado, rechaza la carga; no mezcla datos ficticios con información real. Tampoco cambia la empresa/sucursal configurada ni contraseñas.

| Producto ficticio | Existencia final del escenario |
|---|---|
| Arroz por KG | 44.500 |
| Café por unidad | 53.000 |
| Detergente por LT | 29.000 |
| Cuaderno por unidad | 53.000 |

Puede consultar compras, ventas, inventario, ajustes, Kardex y reportes con esos datos. No ejecute el seeder local antiguo `DemoSeeder` en producción; mantiene su restricción original.

## 10. Crear Render y desplegar

1. Regístrese o ingrese en [Render](https://dashboard.render.com/).
2. Abra **New > Web Service**, conecte su GitHub y autorice el repositorio oficial SICOVI.
3. Elija la rama que ya contenga esta preparación publicada con autorización.
4. Seleccione el runtime **Docker**, contexto `.`, Dockerfile `./Dockerfile`. Deje Docker Command vacío para usar el arranque de la imagen. No coloque `php artisan serve`.
5. Elija región y plan. La aplicación ya se preparó para un contenedor de pocos procesos; confirme memoria y conexiones según el uso real.
6. En Environment copie las variables privadas preparadas. Ajuste `APP_URL` al subdominio HTTPS que Render asigne al servicio.
7. Configure Health Check Path como `/up` y Auto-Deploy como **Off** para mantener el control de las publicaciones.
8. Confirme que Supabase ya tenga migraciones, permisos y administrador. Inicie el primer despliegue desde Render.
9. Revise Build Logs y Runtime Logs. Si el validador rechaza una variable, corrija su valor; no desactive las verificaciones.
10. Abra el enlace `https://NOMBRE.onrender.com` que muestra Render e ingrese con el usuario real.

También puede usar **New > Blueprint** con `render.yaml`: contiene los valores no secretos y pide los secretos con `sync: false`. Esa vía requiere igualmente publicar los archivos primero y completar los pasos de Supabase. La plantilla no crea una base Render ni ejecuta migraciones automáticas.

Render exige un servicio HTTP escuchando en `0.0.0.0` y el puerto esperado; véase [Web Services](https://render.com/docs/web-services). `/up` verifica que Laravel pueda arrancar; no sustituye las comprobaciones de base y módulos del comando de preparación.

### Restricciones de Free

La plantilla usa Free para una exhibición sin contratación implícita. Ese plan puede suspender el servicio por inactividad, pierde archivos locales al recrearse y no ofrece Shell, one-off jobs ni Pre-deploy Command. Por eso las migraciones/administración se ejecutan desde Docker local contra Supabase, y sesiones/caché se guardan en PostgreSQL. Consulte los [límites vigentes de Render Free](https://render.com/docs/free). Los archivos de imágenes versionados se incluyen en la imagen; futuras cargas de archivos de usuarios necesitarán almacenamiento persistente externo.

Supabase Free también tiene límites y puede pausar proyectos inactivos; revise su panel y el estado del proyecto antes de una demostración. Para operación real acuerde disponibilidad y respaldos; no dependa del almacenamiento efímero del contenedor.

## 11. Comprobación del enlace público

- `/up` responde correctamente por HTTPS, sin información de conexión.
- El login, sus imágenes y los recursos `/build/assets/` cargan sin contenido mixto HTTP.
- Iniciar sesión y recargar conserva el acceso. Salir invalida la sesión.
- Almacén Central muestra productos y catálogos; cada cargo ve las acciones autorizadas.
- Compras y Ventas muestran estados, detalles y totales; su confirmación mueve stock una sola vez.
- Inventario, ajustes, Kardex y Reportes coinciden con las existencias.
- Usuarios, Roles y Configuración están disponibles para el administrador real.
- Un usuario sin permisos recibe 403 al intentar acceder directamente a una ruta protegida.

Esta entrega verifica esos fundamentos localmente. La conectividad, certificado público, límites y permisos concretos de sus cuentas Render/Supabase deben comprobarse en el primer despliegue externo.

## 12. Actualizaciones y respaldos

Conserve `APP_KEY`, credenciales y variables. Revise el diff de migraciones antes de publicar, realice respaldo y elija una ventana sin escrituras si la migración necesita bloquear tablas. Mantenga Auto-Deploy desactivado hasta finalizar la preparación.

Use `pg_dump` de la misma versión mayor que el servidor. Para un Supabase 17, un ejemplo PowerShell con contraseña solicitada de forma interactiva es:

```powershell
pg_dump -h HOST_REAL -p 5432 -U sicovi_app.REFERENCIA -d postgres -W --schema=sicovi --no-owner --no-privileges -Fc -f C:\RESPALDOS_PRIVADOS\sicovi-antes-actualizacion.dump
```

Use el usuario sin sufijo en conexión directa. Configure `PGSSLMODE=require` en esa consola o `verify-full` y `PGSSLROOTCERT` con la CA. La carpeta de respaldo debe existir y estar fuera de `public` y Git. Compruebe el archivo con `pg_restore --list` y ensaye su restauración en un destino vacío separado; listar el archivo no demuestra una restauración correcta. No se realizó una restauración externa en esta entrega.

Después, construya la revisión aprobada, ejecute el comando de preparación con `--aplicar`, publique el contenedor y compruebe los módulos. No vuelva a cargar ejemplos para actualizar una instalación con datos. Ante una falla, conserve los datos y corrija incrementalmente; un rollback del código de Render no revierte automáticamente el esquema PostgreSQL.

## 13. Problemas frecuentes

| Problema | Qué revisar |
|---|---|
| Error 500 | Runtime Logs, variables obligatorias, migraciones, APP_KEY válida y permisos. Mantenga `APP_DEBUG=false`. |
| No conecta PostgreSQL | Host exacto de Connect, puerto/modo, rol y sufijo correctos, contraseña, proyecto activo, red y SSL. No use la API key como contraseña PostgreSQL. |
| Error `pg_c_utf8` | Versión 17+ UTF8 y collation existente. No modifique migraciones históricas para evitarlo. |
| Permiso denegado en una migración | Propiedad de `sicovi`, USAGE/CREATE y funciones PL/pgSQL. No otorgue superusuario ni altere esquemas administrados por Supabase. |
| Extensión PHP faltante | Reconstruya el Dockerfile de esta entrega; `composer check-platform-reqs --no-dev` debe pasar. No ignore requisitos de plataforma. |
| Falta manifiesto Vite / imágenes | Build Logs, `public/build/manifest.json`, archivos versionados y sus mayúsculas exactas en Linux. No copie `public/hot` ni `node_modules` local. |
| Nginx no inicia | Variable PORT válida, logs y directorios temporales en `/tmp`. No cambie a root ni use permisos 777. |
| Error 419 / sesión no persiste | Misma APP_KEY entre despliegues, HTTPS, cookies Secure, hora del servidor y tabla `sessions`. No desactive CSRF. |
| Redirección o recursos HTTP | APP_URL HTTPS y TRUSTED_PROXIES en el servicio detrás de Render. No habilite proxies indiscriminadamente en redes no controladas. |
| Caché no existe | Aplicar la migración técnica `2026_10_30_140000_create_deployment_cache_tables`; no crear la tabla a mano ni usar caché efímera para ocultar el error. |
| Plan Free sin consola | Usar los comandos Docker locales con el archivo privado de Supabase. No agregar endpoints web de administración. |
| La preparación informa otro despliegue | Espere al proceso activo. No elimine el bloqueo ni ejecute otra migración en paralelo. |
| Seeder rechaza los ejemplos | Se requiere administrador real con permisos, confirmación explícita y una instalación sin datos operativos. No borre datos para forzarlo. |

## 14. Resultados y acciones pendientes

Consulte [el informe técnico de preparación](DIAGNOSTICO_DESPLIEGUE.md), [la evidencia de verificación](verificacion/despliegue-2026-10-30/RESULTADOS.md) y [la propuesta de PR](PROPUESTA_PR_DESPLIEGUE.md).

Le corresponde crear las cuentas/proyectos externos, comprobar su versión PostgreSQL, guardar los secretos, autorizar la publicación Git, preparar la base elegida y activar el servicio. Después debe validar el HTTPS público y ensayar respaldos/restauración. Los costos, términos de los proveedores y contrataciones quedan bajo su control.
