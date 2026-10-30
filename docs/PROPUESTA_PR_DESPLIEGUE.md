# Propuesta de Pull Request

**Título:** Preparar SICOVI para Render con Docker y PostgreSQL de Supabase

**Base:** `manuel-base-acceso-catalogos`, commit `7ca5c78578a94f0d4ef3b957ab756335ecba5f52`.

**Rama de trabajo local:** `codex/despliegue-render-supabase`.

SICOVI carecía de infraestructura de producción y dependía del entorno Windows local. Se incorpora una imagen PHP-FPM/Nginx sin privilegios, configuración de Render y un procedimiento explícito de preparación de PostgreSQL 17. El contenedor conserva sesiones y caché en la base y valida su configuración antes de iniciar, sin ejecutar migraciones, cambiar APP_KEY o cargar ejemplos en cada reinicio.

Los comandos nuevos comprueban compatibilidad y conciliación de stock. Un seeder separado permite exhibir compras, ventas, ajustes y anulaciones ficticias mediante los servicios existentes; rechaza instalaciones con información previa y evita duplicados. No se modifican módulos de negocio ni migraciones históricas. Se añade únicamente una migración técnica de caché y se corrige el aviso de seguridad de symfony/mime con un parche aislado.

**Validación:** suite completa PHPUnit, pruebas frontend, Composer/plataforma y auditorías de producción, construcción Docker, Blueprint contra esquema oficial Render y ejecución HTTP con PostgreSQL TLS. Se comprobaron el comando oficial de administrador, los módulos, cookies, CSRF, recursos, exclusión de secretos e idempotencia de ejemplos. La sesión y la caché sobreviven a la recreación del contenedor. Resultados exactos en `docs/verificacion/despliegue-2026-10-30/RESULTADOS.md`.

**Operación:** revisar `docs/DESPLIEGUE_RENDER_SUPABASE.md`, preparar secretos/esquema en las cuentas del titular y aplicar migraciones antes de habilitar la revisión pública. No se han publicado ramas, creado este PR en GitHub, integrado cambios ni desplegado externamente. Esta propuesta se presenta para la revisión y autorización correspondientes.
