#!/usr/bin/env bash
# Prepare .env on the VPS before a deploy. Run by .github/workflows/deploy.yml as the deploy user,
# from the app folder. Only fills what is missing; existing values are never overwritten, and no
# secret is printed.
#
#   - .env from .env.example when missing (production settings, new APP_SECRET)
#   - DATABASE_URL for edfolio_blog on the shared core-database-config MySQL: the password from
#     core-database-config/schemas/mysql/edfolio_blog.yml when it works; otherwise the user is
#     created, or reset while no blog container is running
#   - LOGTO_* once the SSO deploy has registered edfolio-blog-webapp
#   - MAILER_DSN to the Mailtrap sandbox when MAILTRAP_USERNAME / MAILTRAP_PASSWORD are passed in
set -euo pipefail

ENV_FILE=.env
SITE_URL=https://blog.edilsonsantos.co.za
DB=edfolio_blog
DB_USER=edfolio_blog_user
MYSQL=core-database-config-mysql
DB_YML=/workspace/datasparklab/core-database-config/schemas/mysql/edfolio_blog.yml
SSO_ENV=/workspace/datasparklab/core-sso-webapp/.env.production
SSO_APP_ID=edfolio-blog-webapp

get_env() { grep -E "^$2=" "$1" 2>/dev/null | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }
set_env() {
  local tmp; tmp="$(mktemp)"
  awk -v k="$1" -v v="$2" 'BEGIN{d=0} index($0, k"=")==1 {print k"="v; d=1; next} {print} END{if(!d) print k"="v}' "$ENV_FILE" > "$tmp"
  cat "$tmp" > "$ENV_FILE"; rm -f "$tmp"
}
mysql_root() { docker exec -i -e MYSQL_PWD="$(docker exec "$MYSQL" printenv MYSQL_ROOT_PASSWORD)" "$MYSQL" mysql -uroot -N "$@"; }
db_login_ok() { [ -n "$1" ] && docker exec -i -e MYSQL_PWD="$1" "$MYSQL" mysql -u"$DB_USER" -N -e "SELECT 1" "$DB" >/dev/null 2>&1; }

echo "Preparing $ENV_FILE"
fresh=0
if [ ! -s "$ENV_FILE" ]; then
  install -m 600 .env.example "$ENV_FILE"
  set_env APP_ENV prod
  set_env APP_SECRET "$(openssl rand -hex 32)"
  set_env SITE_URL "$SITE_URL"
  set_env MESSENGER_TRANSPORT_DSN "doctrine://default?auto_setup=0"
  set_env MAILER_DSN "null://null"
  fresh=1
  echo "  created from .env.example"
fi
chmod 600 "$ENV_FILE"

# Keys the Symfony config reads that older env files may lack (added only when absent)
ensure() { grep -qE "^$1=" "$ENV_FILE" || { set_env "$1" "$2"; echo "  added $1"; }; }
site_url="$(get_env "$ENV_FILE" SITE_URL)"; site_url="${site_url:-$SITE_URL}"
ensure DEFAULT_URI "${site_url%/}"
# Trust the reverse proxy on the docker network so https, host and client IP come through
ensure SYMFONY_TRUSTED_PROXIES private_ranges
ensure SITE_TITLE "\"$(get_env "$ENV_FILE" SITE_NAME | sed 's/^$/ES Blog/')\""

if [ "$fresh" = 1 ] || get_env "$ENV_FILE" DATABASE_URL | grep -q "USER:PASSWORD@HOST"; then
  docker ps --format '{{.Names}}' | grep -qx "$MYSQL" || { echo "::error::Container $MYSQL is not running on the server."; exit 1; }
  pw="$(sed -n "/username: *$DB_USER/,/password:/p" "$DB_YML" 2>/dev/null | sed -n 's/^ *password: *//p' | tr -d "\"'" | head -1 || true)"
  if ! db_login_ok "$pw"; then
    exists="$(mysql_root -e "SELECT COUNT(*) FROM mysql.user WHERE user='$DB_USER'")"
    if [ "$exists" != "0" ] && docker ps --format '{{.Names}}' | grep -qx edfolio-v2-php; then
      echo "::error::$DB_USER exists and a blog container is running, but its password isn't known here. Put DATABASE_URL in $PWD/$ENV_FILE by hand."
      exit 1
    fi
    pw="$(openssl rand -hex 24)"
    mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$pw';
ALTER USER '$DB_USER'@'%' IDENTIFIED BY '$pw';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
SQL
    echo "  set up MySQL database $DB and user $DB_USER"
  fi
  set_env DATABASE_URL "\"mysql://$DB_USER:$pw@dsl-mysql:3306/$DB?serverVersion=8.0&charset=utf8mb4\""
  echo "  set DATABASE_URL"
fi

var="SSO_APP_SECRET_$(printf '%s' "$SSO_APP_ID" | tr '[:lower:]-' '[:upper:]_')"
secret="$( [ -r "$SSO_ENV" ] && get_env "$SSO_ENV" "$var" || true)"
if [ -n "$secret" ] && [ -z "$(get_env "$ENV_FILE" LOGTO_APP_SECRET)" ]; then
  set_env LOGTO_ENDPOINT https://sso.datasparklab.com
  set_env LOGTO_INTERNAL_ENDPOINT http://dsl-sso:3001
  set_env LOGTO_APP_ID "$SSO_APP_ID"
  set_env LOGTO_APP_SECRET "$secret"
  echo "  linked to SSO as $SSO_APP_ID"
fi

if [ -n "${MAILTRAP_USERNAME:-}" ] && [ -n "${MAILTRAP_PASSWORD:-}" ] && ! get_env "$ENV_FILE" MAILER_DSN | grep -q mailtrap; then
  set_env MAILER_DSN "\"smtp://$MAILTRAP_USERNAME:$MAILTRAP_PASSWORD@sandbox.smtp.mailtrap.io:2525\""
  echo "  mail goes to the Mailtrap sandbox"
fi

echo "$ENV_FILE ready"
