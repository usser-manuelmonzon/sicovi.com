# SICOVI — entrega de Manuel

Base del Sistema Integrado de Compras, Ventas e Inventario. Referencias **MANUEL-01 a MANUEL-04**, locales; no representan incidencias Jira verificadas. Trabajo en `codex/manuel-base-acceso-catalogos`, sin commits ni publicación remota.

## Alcance entregado

Laravel con PostgreSQL; inicio/cierre de sesión; cuentas activas, cambio y restablecimiento administrativo de contraseña; usuarios, seis roles y 22 permisos; empresa, sucursal y bodega únicas; categorías, subcategorías, marcas, unidades y productos. Búsqueda, filtros, paginación, detalle, edición y estados. Auditoría transaccional y aritmética decimal exacta con BCMath.

Cada producto nace con un saldo cero, en la misma transacción. No existe un formulario de stock. El costo de referencia solo se envía a usuarios con `products.view_cost`. No hay eliminación de registros con historial.

Proveedores, compras e inventario operativo corresponden a Yourgen; clientes y ventas a Kevin; anulaciones, consultas comerciales e integración a Luis. Esta entrega no implementa esos módulos ni el dossier académico completo.

Se revisaron el TXT maestro de Manuel, las tres páginas de la guía académica y la imagen de distribución vigente. La guía propone cinco alumnos; la organización vigente del equipo tiene cuatro. La venta funcional es una exigencia del proyecto integrado, no de esta primera entrega. Los rótulos inconsistentes de la tabla académica no crean un módulo adicional de servicios. Las fechas del 19 al 27 y el cierre a las 21:00 son acuerdos internos del equipo.

## Versiones verificadas el 19/10/2026

| Componente | Versión efectiva |
|---|---|
| PHP | 8.4.12, BCMath y PDO PostgreSQL |
| Laravel | 13.37.0 |
| Composer | 2.9.4 |
| PostgreSQL servidor | 17.11, UTF8 |
| Node / npm | 22.15.0 / 10.9.2 |
| Bootstrap | 5.3.8 |
| Vite / laravel-vite-plugin | 7.3.7 / 2.1.0 |

Los locks fijan las dependencias instaladas. Use PHP 8.4.x y Node 22.x (mínimo 22.12). Se usó el PHP compatible de Laragon, sin modificar PHP ni MySQL de XAMPP. La ubicación del código sigue siendo `C:\xampp\htdocs\sicovi.com`.

## Abrir esta instalación

La base local contiene configuración y cinco productos ficticios con stock cero. La actualización MANUEL-02 añade seis cargos, casillas de permisos por usuario y cuatro cuentas de demostración locales solicitadas expresamente. MANUEL-04 cambia el acceso a un nombre de usuario corto, independiente del correo. Para una cuenta normal con contraseña propia, use una terminal interactiva:

```powershell
Set-Location C:\xampp\htdocs\sicovi.com
.\scripts\postgres-local.ps1 status
# Solo si el cluster está detenido:
.\scripts\postgres-local.ps1 start
.\scripts\php.ps1 artisan sicovi:crear-administrador
.\scripts\php.ps1 artisan serve --host=127.0.0.1 --port=8000
```

Abra http://127.0.0.1:8000/login e ingrese su **usuario**, por ejemplo `adminm`, y contraseña. El correo ya no es la credencial de inicio. El formulario compacto muestra USUARIO y CONTRASEÑA dentro de los campos; estos textos desaparecen al enfocar el campo. Conserva etiquetas accesibles y ya no muestra el logo.

El comando de administrador normal pide nombre completo, nombre de usuario, correo y contraseña oculta; mínimo 12 caracteres, mayúsculas, minúsculas, número y símbolo, máximo 72 bytes por BCrypt. Repetirlo no sobrescribe cuentas existentes. No hay registro público ni recuperación por correo. El cambio personal o restablecimiento administrativo revoca sesiones; el usuario debe entrar otra vez.

Esta PC tiene PostgreSQL portable privado en `storage/runtime/pgsql`, cluster `storage/runtime/pgdata17`, puerto **55432**, solo loopback. `.env` y `.env.testing` locales ya tienen credenciales independientes y privadas. No se alteró la instalación PostgreSQL 16 existente. El runtime portable y los registros de BD no viajan por Git. `scripts/postgres-local.ps1` solo controla este cluster opcional; no instala ni configura servicios. Deténgalo con `stop` cuando no lo use.

`scripts/php.ps1` prepara un `php.ini` privado ignorado por Git y transmite PHPRC a Artisan y Composer. No cambia el PATH ni configuraciones globales. En esta PC el PHP compatible es `C:\laragon\bin\php\php-8.4.12-nts-Win32-vs17-x64\php.exe`. Compruebe `Get-Command php` antes de usar el script. Con otro PHP ya configurado puede usar `php` directamente.

## Cargos y cuentas de demostración locales

Los cargos disponibles son Administrador, Vendedor, Supervisor, Bodega, Compras y Consulta. Al seleccionar un cargo al crear/editar un usuario, las casillas cargan sus permisos predeterminados. Puede agregar o quitar acciones para esa persona sin cambiar a los demás usuarios del cargo; la base guarda excepciones allow/deny. La administración conserva siempre un administrador activo con acceso efectivo de recuperación.

El **nombre de usuario** se guarda en minúsculas y es único: entre 3 y 40 caracteres ASCII, con letras, números, punto, guion o guion bajo; debe comenzar con letra o número. El mantenimiento Usuarios permite crearlo y editarlo. El correo se conserva como un dato independiente, también normalizado a minúsculas. Cambiar uno no cambia automáticamente el otro, y sus textos no conceden permisos. El mismo mantenimiento asigna cargos/permisos, activa/desactiva y restablece contraseñas. El botón Usar permisos del cargo restaura su selección antes de guardar.

Por solicitud explícita del usuario, esta PC tiene estas credenciales de demostración local con inicial m:

| Cargo | Usuario para ingresar | Correo conservado | Clave de demostración |
| --- | --- | --- | --- |
| Administrador | adminm | adminm@gmail.com | admin |
| Vendedor | vendedorm | vendedorm@gmail.com | vendedor |
| Supervisor | supervisorm | supervisorm@gmail.com | supervisor |
| Bodega | bodegam | bodegam@gmail.com | bodega |

Son cuentas locales de SICOVI; no se crearon buzones Gmail. Escriba el usuario de la segunda columna en el login, sin añadir `@gmail.com`. Para reproducirlas, después de las migraciones y SystemSeeder, habilite SICOVI_DEMO_ACCOUNTS=true en su .env local y ejecute `php artisan sicovi:crear-usuarios-demo --inicial=m` con su PHP configurado. En Windows puede usar scripts/php.ps1. El comando no restablece cuentas existentes ni sus personalizaciones; un conflicto de usuario o correo debe resolverse sin sobrescribir otra cuenta.

Las cuentas is_demo solo operan con la bandera explícita y conexión efectiva loopback a sicovi_local/sicovi (local) o sicovi_test/sicovi (testing). Se bloquean en producción y al desactivar la bandera, incluso con sesión anterior. Cree un administrador normal con contraseña propia antes de abandonar la demostración. Las cuentas normales mantienen su contraseña fuerte; las claves simples no se habilitan para su creación o restablecimiento ordinarios. Los .env.example dejan la bandera apagada.

## Instalación desde cero en otra computadora

1. Obtenga el código en una carpeta propia. Instale PHP 8.4, Composer 2, Node 22.12+ y PostgreSQL 17. No clone otro repositorio dentro de este. Habilite BCMath, PDO PostgreSQL y extensiones de Laravel.
2. Prepare las bases mediante una sesión PostgreSQL administrativa autorizada. El archivo `scripts/postgresql/preparar.psql` crea un usuario sin superusuario ni permiso de crear bases/roles, tres bases UTF8 y sus esquemas. Es para **primera preparación**: se detiene si los objetos ya existen. La contraseña se introduce mediante `\password`, sin escribirla en el archivo o en argumentos.

```powershell
# Use el binario PostgreSQL 17 de su instalación; -W solicita contraseña oculta.
psql -h 127.0.0.1 -p 5432 -U postgres -d postgres -W -f scripts/postgresql/preparar.psql
Copy-Item .env.example .env
Copy-Item .env.testing.example .env.testing
```

3. Edite ambas configuraciones localmente. Desarrollo: `sicovi_local`; pruebas: `sicovi_test`; usuario: `sicovi_app`; esquema: `sicovi`. Configure su contraseña y puerto. No copie credenciales de otro integrante. Mantenga caché file, queue sync y sesiones database en desarrollo. Fechas internas UTC y presentación Guatemala.
4. Instale y verifique con el PHP compatible. Estos comandos son para un entorno **nuevo**; no regenere una APP_KEY existente.

```powershell
.\scripts\php.ps1 -v
# PHPRC queda en esta terminal para los procesos de Composer.
composer install --no-interaction --prefer-dist
composer check-platform-reqs
npm.cmd ci
.\scripts\php.ps1 artisan key:generate
.\scripts\php.ps1 artisan key:generate --env=testing
.\scripts\php.ps1 artisan migrate --force
.\scripts\php.ps1 artisan db:seed --class=SystemSeeder --force
# Solo demostración local, opcional:
.\scripts\php.ps1 artisan db:seed --class=DemoSeeder
.\scripts\php.ps1 artisan sicovi:crear-administrador
npm.cmd run build
.\scripts\php.ps1 scripts/diagnostico.php
.\scripts\php.ps1 artisan serve --host=127.0.0.1 --port=8000
```

El diagnóstico confirma `current_database()` y `current_schema()`, motor y conteos sin credenciales. DB_SCHEMA controla realmente search_path; todas las tablas de aplicación, migraciones y sesiones quedan en `sicovi`. El esquema debe existir antes de migrar. La segunda migración usa la collation Unicode incorporada `pg_c_utf8` de PostgreSQL 17 para tratar coherentemente mayúsculas con tildes. Si encuentra duplicados previos, se detiene sin fusionar ni borrar filas.

La migración `2026_10_19_000005_add_usernames_to_users.php` añade el usuario de acceso y asigna uno a las cuentas existentes en orden de ID. Usa como punto de partida el texto anterior a `@` del correo, convertido a ASCII y minúsculas, reemplaza caracteres incompatibles y resuelve colisiones con sufijos `-2`, `-3`, etc., respetando el máximo de 40 caracteres. Si el resultado inicial es demasiado corto, utiliza el prefijo `usuario-`; si queda vacío, usa `usuario`. Esta asignación inicial no vincula permanentemente correo y usuario. Consulte el usuario asignado en Usuarios y edítelo si corresponde; se conservan contraseña, estado, cargo y permisos existentes.

Para Apache, el DocumentRoot debe apuntar exclusivamente a `public`. No sirva la raíz del repositorio ni mueva archivos privados a public. En producción, APP_DEBUG=false, HTTPS y cookies seguras; el despliegue no forma parte de esta entrega.

## Pruebas y revisión

```powershell
.\scripts\php.ps1 vendor/bin/phpunit --colors=never
# La suite deja las tablas de prueba sin sus fixtures transaccionales.
$env:APP_ENV='testing'
.\scripts\php.ps1 tests/Concurrency/run_admin_race.php
Remove-Item Env:APP_ENV
npm.cmd run build
composer validate --strict --no-check-publish
composer check-platform-reqs
.\scripts\php.ps1 artisan route:list --except-vendor
.\scripts\php.ps1 artisan view:cache
.\scripts\php.ps1 vendor/bin/pint --test app config database routes tests
```

PHPUnit fuerza `testing`, PostgreSQL y `sicovi_test`. Antes de RefreshDatabase, una guarda consulta la conexión **efectiva**: debe ser PostgreSQL, base exacta sicovi_test y esquema sicovi; una configuración cacheada hacia otra base aborta antes de limpiar. No cambie ese control para hacer pasar pruebas. Las unitarias decimales no necesitan BD.

La carrera administrativa usa dos conexiones/procesos reales y una barrera; verifica espera por el bloqueo, un administrador activo y una sola desactivación auditada. Exige base de pruebas sin usuarios y conserva sus fixtures. Para repetirla ejecute primero la suite protegida. Nunca ejecute estos helpers sobre desarrollo. La carrera de última unidad de inventario/ventas pertenece a etapas futuras.

La suite anterior al cambio de usuario de acceso registró **168 pruebas y 1,019 aserciones correctas**. Su detalle está en `docs/pruebas/MATRIZ_MANUEL_02.md` y `docs/evidencias/manuel/roles_usuarios/LEEME.md`; se conserva la matriz inicial como histórico. **La migración, autenticación y presentación de MANUEL-04 aún tienen su ejecución y evidencia pendientes de registrar.** El resultado anterior no certifica esta modificación nueva.

Hay capturas del inicio, listado de escritorio y formulario/menú móvil; las referencias 1366×768 y 360×800 se revisaron con iframes de dimensiones CSS exactas, mostrados al 75%, no con dispositivos físicos. La captura de MANUEL-03 con logo corresponde a la versión visual anterior del login. Los archivos temporales de revisión no forman parte del producto.

## Actualización conservando datos

Primero respalde; revise el TXT de cambios y cualquier migración nueva. Conserve .env, .env.testing, APP_KEY y datos. Detenga el servidor para evitar escrituras durante el mantenimiento y ejecute:

```powershell
.\scripts\php.ps1 -v
composer install --no-interaction --prefer-dist
npm.cmd ci
.\scripts\php.ps1 artisan config:clear
.\scripts\php.ps1 artisan migrate --force
.\scripts\php.ps1 artisan db:seed --class=SystemSeeder --force
npm.cmd run build
.\scripts\php.ps1 artisan view:clear
.\scripts\php.ps1 scripts/diagnostico.php
```

No use migrate:fresh, db:wipe, truncados ni regeneración de claves como actualización. SystemSeeder crea seis roles/permisos/configuración/unidades faltantes sin restituir permisos o preferencias modificados. DemoSeeder solo crea datos con claves estables faltantes, no restaura precios, estados ni saldos editados; bloqueado fuera de local/testing. No es un paso normal de actualización.

Para MANUEL-04, `migrate --force` aplica también la migración 000005 y asigna usuarios a las cuentas existentes. Revise esos nombres antes de comunicar el nuevo acceso. No necesita recrear cuentas, cambiar correos, restablecer contraseñas ni volver a ejecutar el comando demo para convertirlas al acceso por usuario.

## Respaldo y recuperación

Use pg_dump/pg_restore 17 compatibles, contraseña interactiva `-W`, y un destino privado fuera de public y Git. Sustituya el puerto por el suyo (55432 en esta PC).

```powershell
pg_dump -h 127.0.0.1 -p 55432 -U sicovi_app -W -d sicovi_local -Fc -f C:\RESPALDOS_PRIVADOS\sicovi.dump
# Solo con sicovi_verificacion VACÍA y autorizada; jamás sobre sicovi_local:
pg_restore -h 127.0.0.1 -p 55432 -U sicovi_app -W -d sicovi_verificacion --no-owner --no-privileges --exit-on-error C:\RESPALDOS_PRIVADOS\sicovi.dump
```

El ejemplo exige una carpeta real elegida por el operador. La base de verificación actual contiene datos de QA; no es un destino vacío para ejecutar el ejemplo. Prepare un destino vacío separado, sin esquema sicovi precreado, antes de restaurar, conserve APP_KEY para sesiones/datos cifrados y valide conteos/relaciones. No se ejecutó ni cronometró una restauración; RNF-06 sigue pendiente. Los respaldos no se versionan.

## Documentos de relevo

- `docs/usuarios/CARGOS_Y_ACCESOS.md`: cargos, casillas individuales y cuentas de demostración.
- `docs/cambios/MANUEL-02_roles_usuarios_permisos.txt`: actualización de cargos, correos y permisos.
- `docs/cambios/MANUEL-03_login_logo.txt`: antecedente visual del login; su logo deja de mostrarse en MANUEL-04.
- `docs/cambios/MANUEL-04_login_usuario.txt`: acceso por usuario corto, migración de cuentas existentes y validación pendiente.
- `docs/arquitectura/BASE_TECNICA_MANUEL.md`: decisiones y capas.
- `docs/datos/DICCIONARIO_BASE_MANUEL.md`: esquema real de esta fase.
- `docs/contratos/CONTINUIDAD_MODULOS.md`: servicios actuales y trabajo futuro.
- `docs/pruebas/MATRIZ_MANUEL.md`: M-01 a M-20 y evidencia.
- `docs/cambios/MANUEL-01_base_acceso_catalogos.txt`: instalación, actualización y restricciones.
- `docs/entregas/01_MANUEL_A_YOURGEN.md`: recepción técnica y tareas del siguiente autor.

Está pendiente que Yourgen u otro compañero instale y confirme en su propia PC. No se verificaron Jira ni permisos remotos y no se crearon incidencias, commits, PR, invitaciones o despliegues. GitHub sincroniza código; cada integrante prepara su propia BD local.

Referencias técnicas consultadas: [Laravel 13](https://laravel.com/docs/13.x/releases), [PostgreSQL 17: esquemas](https://www.postgresql.org/docs/17/ddl-schemas.html), [collations Unicode](https://www.postgresql.org/docs/17/collation.html), [Vite 7](https://vite.dev/blog/announcing-vite7). El ZIP portable procede del [distribuidor oficial EDB](https://get.enterprisedb.com/postgresql/postgresql-17.11-3-windows-x64-binaries.zip); SHA256 comprobado: `4b8db0930c38f6ef845db919551dedda3b6b845aeb0927b3d79a6e8e9e4537cf`.
