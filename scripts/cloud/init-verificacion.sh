#!/bin/sh
set -eu
# Executed by the official entrypoint only in a NEW disposable sicovi_test cluster.
[ "$POSTGRES_DB" = "sicovi_test" ] || exit 1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=database' \
    -addext 'subjectAltName=DNS:database' -keyout "$PGDATA/server.key" -out "$PGDATA/server.crt" >/dev/null 2>&1
chmod 600 "$PGDATA/server.key"
printf "\nssl=on\nssl_cert_file='server.crt'\nssl_key_file='server.key'\n" >> "$PGDATA/postgresql.conf"
psql -v ON_ERROR_STOP=1 --username postgres --dbname sicovi_test -v verify_password="$VERIFY_APP_PASSWORD" <<'SQL'
CREATE ROLE sicovi_app LOGIN PASSWORD :'verify_password';
CREATE ROLE anon;
CREATE ROLE authenticated;
SQL
psql -v ON_ERROR_STOP=1 --username postgres --dbname sicovi_test -f /opt/sicovi-supabase.sql
