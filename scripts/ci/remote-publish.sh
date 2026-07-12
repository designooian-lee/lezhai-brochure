#!/usr/bin/env bash
set -Eeuo pipefail

SITE="/opt/1panel/www/sites/lezhai/index"
APP_DIR="/opt/apps/lezhai-brochure"
ENV_FILE="$APP_DIR/.env"
STAGE="/tmp/lezhai-brochure-release"
IMAGE_ARCHIVE="/tmp/lezhai-brochure-image.tar.gz"
RELEASE_ARCHIVE="/tmp/lezhai-brochure-release.tgz"
TS="$(date +%Y%m%d-%H%M%S)"
BACKUP="/opt/1panel/www/sites/lezhai/index.backup-brochure-$TS"
DB_BACKUP="$APP_DIR/backups/Website-$TS.sql.gz"
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

[ "$SITE" = "/opt/1panel/www/sites/lezhai/index" ] || exit 10
test -f "$ENV_FILE"
test -f "$IMAGE_ARCHIVE"
test -f "$RELEASE_ARCHIVE"

rm -rf "$STAGE"
mkdir -p "$STAGE" "$APP_DIR/backups"
tar -xzf "$RELEASE_ARCHIVE" -C "$STAGE"
test -f "$STAGE/docker-compose.production.yml"
test -f "$STAGE/release.env"

DB_PASSWORD="$(sed -n 's/^DB_PASSWORD=//p' "$ENV_FILE" | tail -n 1)"
test -n "$DB_PASSWORD"
docker exec -e PGPASSWORD="$DB_PASSWORD" 1Panel-postgresql-4AAi \
  pg_dump -U Website -d Website | gzip -c > "$DB_BACKUP"
test -s "$DB_BACKUP"

mkdir -p "$SITE"
cp -a "$SITE" "$BACKUP"
docker load -i "$IMAGE_ARCHIVE"

find "$SITE" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
cp -a "$STAGE/." "$SITE/"
SITE_REPLACED=1
chown -R root:root "$SITE"
find "$SITE" -type d -exec chmod 755 {} +
find "$SITE" -type f -exec chmod 644 {} +

docker compose -p "$COMPOSE_PROJECT" \
  --env-file "$SITE/release.env" \
  -f "$SITE/docker-compose.production.yml" up -d --no-build

for attempt in $(seq 1 30); do
  if curl -fsS http://127.0.0.1:4327/health >/dev/null; then
    rm -rf "$STAGE" "$IMAGE_ARCHIVE" "$RELEASE_ARCHIVE" /tmp/remote-publish.sh
    echo "Published to $SITE"
    echo "Site backup: $BACKUP"
    echo "Database backup: $DB_BACKUP"
    exit 0
  fi
  sleep 2
done

docker compose -p "$COMPOSE_PROJECT" -f "$SITE/docker-compose.production.yml" logs --tail=100 app
exit 1
