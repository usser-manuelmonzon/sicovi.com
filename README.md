# SICOVI — Sistema Integrado de Compras, Ventas e Inventario

SICOVI es una aplicación web desarrollada con Laravel para administrar
operaciones comerciales, catálogo de productos, clientes, compras,
ventas e inventario. El sistema incorpora controles de acceso por
permisos, registro de auditoría y validaciones para mantener la
consistencia de las operaciones.

> **Importante:** antes de ejecutar migraciones o pruebas en una
> instalación existente, verifica la base de datos configurada en
> `.env`. No ejecutes comandos que borren o reinicien la base de datos
> en un entorno con información real.

## Funcionalidades

### Catálogo y usuarios

-   Administración de usuarios, roles y permisos.
-   Catálogo de productos y control de estado activo/inactivo.
-   Control de acceso a las funciones mediante permisos y Gates de
    Laravel.
-   Seeder de permisos del sistema para configurar roles y accesos.

### Clientes

-   Registro, consulta, edición y búsqueda de clientes.
-   Datos del cliente: nombre, NIT/identificación tributaria, teléfono,
    correo y dirección.
-   Validación del nombre y normalización del identificador tributario
    para prevenir duplicados.
-   Desactivación de clientes sin eliminarlos físicamente. La reactivación
    no forma parte del flujo actual.
-   Los clientes inactivos no deben seleccionarse para crear nuevas
    ventas; el servicio también debe validar esta regla en el servidor.

### Ventas

-   Creación y consulta de ventas en estado borrador.
-   Registro de productos, cantidades y precios unitarios en el detalle
    de la venta.
-   Cálculo de subtotales y total en el servidor; no se confía en los
    totales enviados por el navegador.
-   Confirmación de ventas con transacciones de base de datos.
-   Validación de existencias antes de confirmar.
-   Descuento de inventario al confirmar una venta, mediante el servicio
    central de inventario.
-   Protección para evitar que una misma confirmación descuente
    existencias más de una vez.
-   Registro de quién creó y confirmó la venta, fecha de confirmación y
    clave de idempotencia.
-   Auditoría de ventas y de sus líneas.
-   Las ventas confirmadas no se editan como si fueran borradores.

### Compras e inventario

-   Módulos de compras y proveedores disponibles en el proyecto.
-   Recepción de compras y aumento de existencias a través de
    movimientos de inventario.
-   Ajustes de inventario con validaciones.
-   Registro de movimientos con cantidades y saldos
    anteriores/posteriores.
-   Prevención de existencias negativas.
-   Restricciones e índices en la base de datos para reforzar la
    integridad de los movimientos.
-   Protección de operaciones mediante claves de idempotencia cuando
    corresponde.

### Auditoría y consistencia

-   Registro de operaciones relevantes mediante el servicio de
    auditoría.
-   Operaciones críticas ejecutadas dentro de transacciones.
-   Restricciones de base de datos para proteger relaciones y estados
    válidos.
-   Validación de movimientos de inventario vinculados con líneas de
    venta o compra.

## Tecnologías

-   **Backend:** PHP y Laravel.
-   **Base de datos:** PostgreSQL.
-   **Frontend:** Blade, JavaScript y Vite.
-   **Gestión de dependencias PHP:** Composer.
-   **Dependencias y compilación frontend:** npm.

Versiones usadas durante el desarrollo: - PHP 8.4.x (el proyecto
requiere PHP `^8.3`). - PostgreSQL 17. - Node.js 22.x. - Composer 2.x.

Consulta `composer.json` y `package.json` para conocer las restricciones
exactas de dependencias de la versión del código que estés utilizando.

## Requisitos previos

Instala y configura: 1. PHP compatible con `composer.json`, con las
extensiones requeridas por Laravel y el controlador PDO de PostgreSQL
(`pdo_pgsql`). 2. Composer. 3. PostgreSQL. 4. Node.js y npm. 5. Git.

## Instalación local

Clona el repositorio y entra en la carpeta del proyecto:

``` bash
git clone <URL_DEL_REPOSITORIO>
cd <CARPETA_DEL_PROYECTO>
```

Instala las dependencias:

``` bash
composer install
npm install
```

Crea el archivo de configuración local y genera la clave de Laravel:

``` bash
cp .env.example .env
php artisan key:generate
```

En Windows PowerShell, si no existe `.env`, puedes copiarlo así:

``` powershell
Copy-Item .env.example .env
php artisan key:generate
```

Edita `.env` y configura la conexión a PostgreSQL. Ejemplo orientativo:

``` dotenv
APP_NAME=SICOVI
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sicovi_local
DB_USERNAME=sicovi_app
DB_PASSWORD=CAMBIAR_POR_TU_CLAVE
DB_SCHEMA=sicovi
```

Adapta el puerto, la base de datos, el usuario, la contraseña y el
esquema a tu instalación. En algunos entornos de desarrollo usados para
este proyecto PostgreSQL está configurado en el puerto `5433`; usa el
puerto real de tu servidor.

Asegúrate de que la base de datos y el esquema existan y de que el
usuario configurado tenga los permisos necesarios.

### Migraciones

En una **base de datos nueva y vacía**, después de revisar las
migraciones y la configuración, ejecuta:

``` bash
php artisan migrate
```

No ejecutes `migrate:fresh`, `db:wipe`, `rollback` ni comandos de
limpieza contra una base de datos con información que quieras conservar.
Si estás incorporando el código a una base de datos existente, revisa
primero `php artisan migrate:status`, confirma que `DB_DATABASE` y el
esquema `sicovi` sean los correctos y crea una copia de seguridad. Luego
ejecuta `php artisan migrate` para aplicar solo las migraciones pendientes.
Las migraciones correctivas agregan guardas e índices sin reinicializar
las tablas. Nunca uses `migrate:fresh`, `db:wipe`, eliminación manual de
tablas ni regeneres `APP_KEY` para actualizar una instalación existente.

### Compilar los recursos y ejecutar

``` bash
npm run build
php artisan optimize:clear
php artisan serve
```

Abre la URL local que indique Artisan, normalmente
`http://127.0.0.1:8000`.

Para desarrollo frontend con recarga en caliente, puedes utilizar:

``` bash
npm run dev
```

## Permisos

El acceso a las funciones se controla mediante permisos del sistema.
Entre los permisos añadidos para ventas se encuentran:

-   `sales.view`
-   `sales.create`
-   `sales.update`
-   `sales.confirm`

La disponibilidad de cada acción depende del rol y de los permisos
asignados al usuario. El seeder `SystemSeeder` define permisos y
asignaciones iniciales. Ejecútalo con `php artisan db:seed --class=SystemSeeder`
solo después de revisar el entorno. Los permisos nuevos se incorporan a
los roles existentes una sola vez; ejecutar de nuevo el seeder no restaura
permisos que alguien revocó manualmente. No hay scripts auxiliares de
concesión de permisos para ventas o clientes.

## Estado del módulo de clientes y ventas

Clientes, borradores, confirmación de ventas, descuentos a través de
`InventoryService`, auditoría e idempotencia están implementados. Los
precios se toman del catálogo al crear el borrador y se actualizan al
confirmar; el importe final queda registrado en la venta confirmada. La
anulación de ventas y las reversiones de sus salidas quedan pendientes de
integración con el servicio compartido de anulaciones para conservar una
sola ruta de reversión en el Kardex.

## Rutas principales

Las rutas web se definen en `routes/web.php`. Los módulos incorporados
incluyen recursos para clientes y ventas, además de una ruta para
confirmar una venta. Para revisar las rutas registradas en tu
instalación:

``` bash
php artisan route:list
```

## Estructura relevante

``` text
app/
├── Http/Controllers/
│   ├── CustomerController.php
│   └── SaleController.php
├── Models/
│   ├── Customer.php
│   ├── Sale.php
│   └── SaleItem.php
├── Providers/
│   └── AppServiceProvider.php
└── Services/
    ├── AuditService.php
    ├── CustomerService.php
    ├── InventoryService.php
    └── SaleService.php

database/
├── migrations/
└── seeders/
    └── SystemSeeder.php

resources/views/
├── customers/
├── sales/
└── layouts/

routes/
└── web.php
```

## Reglas importantes del negocio

-   El total de una venta se calcula en el backend.
-   No se permite confirmar una venta si no hay existencias suficientes.
-   La confirmación de venta y el movimiento de inventario deben quedar
    consistentes.
-   Una confirmación repetida no debe generar un segundo descuento de
    inventario.
-   Los movimientos de inventario no deben dejar existencias negativas.
-   Las operaciones críticas deben respetar las transacciones y
    restricciones de la base de datos.
-   Los clientes inactivos no deben utilizarse en nuevas ventas.
-   Los procesos de anulación/reversión deben coordinarse con el módulo
    responsable de esas operaciones; no se deben simular editando
    directamente una venta confirmada o borrando movimientos.

## Comprobaciones útiles

Verificar sintaxis de rutas y revisar las rutas registradas:

``` bash
php artisan route:list
php artisan view:cache
php artisan view:clear
npm run build
```

Antes de probar operaciones que modifican datos, configura una base de
datos de pruebas separada y verifica la conexión activa. No uses la base
de datos local con información real para pruebas destructivas.

Las pruebas de clientes y ventas se ejecutan así. La prueba real de
concurrencia abre dos procesos PHP y conexiones PostgreSQL independientes:

``` bash
php artisan test --filter='SaleCustomerServiceTest|SalesSchemaTest'
```

``` powershell
$env:APP_ENV = 'testing'
php tests/Concurrency/run_sale_confirmation_race.php
```

El script verifica la protección de `TestDatabaseGuard`, usa la conexión
de `.env.testing` y conserva sus fixtures de prueba para no borrar datos.

## Seguridad y configuración

-   No subas `.env`, contraseñas, tokens, claves privadas ni
    credenciales a GitHub.
-   Mantén `APP_DEBUG=false` en producción.
-   Usa credenciales distintas para desarrollo, pruebas y producción.
-   Configura los permisos mínimos necesarios para el usuario de
    PostgreSQL.
-   Realiza copias de seguridad antes de aplicar migraciones en una
    instalación existente.
-   Revisa las asignaciones de permisos y los seeders antes de
    ejecutarlos en producción.

## Estado del alcance

Este README describe las funcionalidades incorporadas y el
comportamiento previsto de los módulos a partir de los cambios del
proyecto. No implica que se hayan realizado pruebas automatizadas
exhaustivas en todos los escenarios ni que el sistema incluya
facturación electrónica FEL/SAT, cobros, cuentas por cobrar o
integraciones externas de facturación.

## Contribuciones

Para proponer cambios, trabaja en una rama independiente y abre un Pull
Request para revisión antes de integrar los cambios en la rama principal
del proyecto.
