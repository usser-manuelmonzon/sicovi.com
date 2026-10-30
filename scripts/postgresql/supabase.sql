-- Run explicitly in the Supabase SQL Editor, role postgres.
-- First create a LOGIN role named sicovi_app in Database > Roles, with a private
-- strong password, without superuser/createdb/createrole/bypassrls privileges.
-- No passwords belong in this file. Use a NEW project/database for initial setup.
BEGIN;
DO $guard$
BEGIN
    IF current_setting('server_version_num')::int < 170000
       OR current_setting('server_encoding') <> 'UTF8'
       OR to_regcollation('pg_catalog.pg_c_utf8') IS NULL THEN
        RAISE EXCEPTION 'SICOVI requiere PostgreSQL 17+ UTF8 y pg_catalog.pg_c_utf8; no sustituir collations.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'sicovi_app' AND rolcanlogin) THEN
        RAISE EXCEPTION 'Cree primero sicovi_app como rol LOGIN con contraseña privada en Database > Roles.';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_namespace n JOIN pg_roles r ON r.oid=n.nspowner WHERE n.nspname='sicovi' AND r.rolname <> 'sicovi_app') THEN
        RAISE EXCEPTION 'El esquema sicovi ya tiene otro propietario. Revise la instalación; no se reasignará automáticamente.';
    END IF;
END
$guard$;
GRANT sicovi_app TO postgres;
GRANT CONNECT ON DATABASE postgres TO sicovi_app;
CREATE SCHEMA IF NOT EXISTS sicovi AUTHORIZATION sicovi_app;
REVOKE ALL ON SCHEMA sicovi FROM PUBLIC, anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE sicovi_app IN SCHEMA sicovi REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE sicovi_app IN SCHEMA sicovi REVOKE ALL ON TABLES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE sicovi_app IN SCHEMA sicovi REVOKE ALL ON SEQUENCES FROM anon, authenticated;
COMMIT;
-- Do not add sicovi to the schemas exposed by Supabase Data API.
-- Laravel connects via PDO; Supabase anon/service_role API keys are not DB passwords.
