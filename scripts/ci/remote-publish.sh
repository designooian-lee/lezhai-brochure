#!/usr/bin/env bash
set -Eeuo pipefail

SITE="/opt/1panel/www/sites/lezhai.life/index"
APP_DIR="/opt/apps/lezhai-brochure"
ENV_FILE="$APP_DIR/.env"
SOURCE="$APP_DIR/source"
STAGE="/tmp/lezhai-brochure-release"
RELEASE_ARCHIVE="/tmp/lezhai-brochure-release.tgz"
TS="$(date +%Y%m%d-%H%M%S)"
BACKUP="/opt/1panel/www/sites/lezhai.life/index.backup-brochure-$TS"
DB_BACKUP="$APP_DIR/backups/lezhai-$TS.sql.gz"
COMPOSE_PROJECT="lezhai-brochure"
SITE_REPLACED=0

rollback_site() {
  if [ "$SITE_REPLACED" -eq 1 ] && [ -d "$BACKUP" ]; then
    find "$SITE" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
    cp -a "$BACKUP/." "$SITE/"
    if [ -f "$SITE/docker-compose.production.yml" ] && [ -f "$SITE/release.env" ]; then
      docker compose -p "$COMPOSE_PROJECT" \
        --env-file "$SITE/release.env" \
        -f "$SITE/docker-compose.production.yml" up -d --no-build || true
    fi
    echo "Publish failed; restored $BACKUP" >&2
  fi
}

trap rollback_site ERR
trap 'rm -rf "$STAGE" "$RELEASE_ARCHIVE" /tmp/remote-publish.sh' EXIT

[ "$SITE" = "/opt/1panel/www/sites/lezhai.life/index" ] || exit 10
test -f "$ENV_FILE"
test -d "$SOURCE"
test -f "$RELEASE_ARCHIVE"

rm -rf "$STAGE"
mkdir -p "$STAGE" "$APP_DIR/backups"
tar -xzf "$RELEASE_ARCHIVE" -C "$STAGE"
test -f "$STAGE/docker-compose.production.yml"
test -f "$STAGE/release.env"
test -f "$STAGE/build.env"

CURRENT_IMAGE="$(sed -n 's/^LEZHAI_IMAGE=//p' "$STAGE/release.env" | tail -n 1)"
EXPECTED_SHA="${CURRENT_IMAGE#lezhai-brochure:}"
test -n "$EXPECTED_SHA"
test "$(cat "$SOURCE/.lezhai-source-sha")" = "$EXPECTED_SHA"

read_build_env() {
  sed -n "s/^$1=//p" "$STAGE/build.env" | tail -n 1 | base64 -d
}

docker build \
  --build-arg PUBLIC_SITE_ENV=production \
  --build-arg ALPINE_MIRROR=https://mirrors.cloud.tencent.com/alpine \
  --build-arg NPM_REGISTRY=https://registry.npmmirror.com \
  --build-arg "PUBLIC_FORM_ENDPOINT=$(read_build_env PUBLIC_FORM_ENDPOINT_B64)" \
  --build-arg "PUBLIC_STATICFORMS_API_KEY=$(read_build_env PUBLIC_STATICFORMS_API_KEY_B64)" \
  --build-arg "PUBLIC_FORM_SUBJECT=$(read_build_env PUBLIC_FORM_SUBJECT_B64)" \
  --build-arg "PUBLIC_PHONE=$(read_build_env PUBLIC_PHONE_B64)" \
  --build-arg "PUBLIC_ADDRESS=$(read_build_env PUBLIC_ADDRESS_B64)" \
  --build-arg "PUBLIC_BUSINESS_HOURS=$(read_build_env PUBLIC_BUSINESS_HOURS_B64)" \
  -t "$CURRENT_IMAGE" "$SOURCE"

read_env() {
  sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1
}

DB_CONTAINER="$(read_env DB_CONTAINER)"
DB_PORT="$(read_env DB_PORT)"
DB_NAME="$(read_env DB_NAME)"
DB_USER="$(read_env DB_USER)"
DB_PASSWORD="$(read_env DB_PASSWORD)"
test -n "$DB_CONTAINER"
test -n "$DB_PORT"
test -n "$DB_NAME"
test -n "$DB_USER"
test -n "$DB_PASSWORD"
docker exec -e PGPASSWORD="$DB_PASSWORD" "$DB_CONTAINER" \
  pg_dump -h 127.0.0.1 -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" | gzip -c > "$DB_BACKUP"
test -s "$DB_BACKUP"

mkdir -p "$SITE"
cp -a "$SITE" "$BACKUP"

find "$SITE" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
cp -a "$STAGE/docker-compose.production.yml" "$STAGE/release.env" "$SITE/"
SITE_REPLACED=1
chown -R root:root "$SITE"
find "$SITE" -type d -exec chmod 755 {} +
find "$SITE" -type f -exec chmod 644 {} +

docker compose -p "$COMPOSE_PROJECT" \
  --env-file "$SITE/release.env" \
  -f "$SITE/docker-compose.production.yml" up -d --no-build

for attempt in $(seq 1 30); do
  if curl -fsS http://127.0.0.1:4327/health >/dev/null; then
    while IFS= read -r image; do
      [ "$image" = "$CURRENT_IMAGE" ] || docker image rm "$image" || true
    done < <(docker images lezhai-brochure --format '{{.Repository}}:{{.Tag}}')
    for backup in "$SITE".backup-brochure-*; do
      [ ! -d "$backup" ] || [ "$backup" = "$BACKUP" ] || rm -rf -- "$backup"
    done
    for backup in "$APP_DIR"/backups/lezhai-*.sql.gz; do
      [ ! -f "$backup" ] || [ "$backup" = "$DB_BACKUP" ] || rm -f -- "$backup"
    done
    echo "Published to $SITE"
    echo "Site backup: $BACKUP"
    echo "Database backup: $DB_BACKUP"
    exit 0
  fi
  sleep 2
done

docker compose -p "$COMPOSE_PROJECT" -f "$SITE/docker-compose.production.yml" logs --tail=100 app
exit 1
