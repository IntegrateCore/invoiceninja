#!/usr/bin/env bash
set -euo pipefail

ui=98090aa2595fddca5d1e8433c4436d1292245ec7
ui_base=6911bc446518e50b38190a00ae789c55da8308a8
image=invoiceninja:combined-validation

git rev-parse HEAD HEAD^{tree}
grep -Fx '5.14.0' VERSION.txt
grep -Fx 'FROM php:8.4.27-fpm-bookworm@sha256:6d2c08c2ce0548e9bc55e21aab68f5a2613e635e4225fdea84290459728995e7 AS php-runtime' Dockerfile
composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist
CYPRESS_INSTALL_BINARY=0 npm ci
npm run build

git init -q invoiceninja-ui
git -C invoiceninja-ui fetch --no-tags --depth=2 https://github.com/IntegrateCore/invoiceninja-ui.git "$ui"
git -C invoiceninja-ui checkout --detach "$ui"
test "$(git -C invoiceninja-ui rev-parse HEAD)" = "$ui"
git -C invoiceninja-ui merge-base --is-ancestor "$ui_base" "$ui"
(
  cd invoiceninja-ui
  sed 's/VITE_IS_TEST=true/VITE_IS_TEST=false/' .env.example > .env
  echo VITE_PUSHER_APP_KEY=f0111a02782708da651f >> .env
  cp ../vite.config.ts.react ./vite.config.js
  CYPRESS_INSTALL_BINARY=0 npm ci
  npm run build
  cp -r dist/* ../public/
)
test -s public/index.html

docker build --platform linux/amd64 --tag "$image" .
docker image inspect --format '{{.Id}} {{.Os}}/{{.Architecture}}' "$image"
docker run --rm --entrypoint php "$image" -r 'if (PHP_VERSION_ID !== 80427) exit(1); foreach (["bcmath","exif","gd","intl","soap","Zend OPcache"] as $ext) { if (phpversion($ext) !== PHP_VERSION) exit(1); } echo PHP_VERSION, PHP_EOL;'
docker run --rm --entrypoint php-fpm "$image" -v | grep -F 'PHP 8.4.27'
docker run --rm --entrypoint php-fpm "$image" -t
docker run --rm --entrypoint sh "$image" -ec 'grep -Fx 5.14.0 VERSION.txt; test -s public/index.html'

# Dev dependencies go into a disposable container, never into the candidate image.
docker run --name ninja-validation-app --entrypoint sleep -d "$image" infinity
trap 'docker rm -fv ninja-validation-app ninja-validation-db >/dev/null 2>&1 || true; docker network rm ninja-validation >/dev/null 2>&1 || true' EXIT
docker exec ninja-validation-app composer install --no-scripts --no-interaction --no-progress --prefer-dist
docker network create --internal ninja-validation
docker run --rm --name ninja-validation-db --network ninja-validation -e MYSQL_ROOT_PASSWORD=validation-only -e MYSQL_DATABASE=ninja -e MYSQL_USER=ninja -e MYSQL_PASSWORD=validation-only --tmpfs /var/lib/mysql -d mysql:8.0
docker network connect ninja-validation ninja-validation-app
docker network disconnect bridge ninja-validation-app
for attempt in {1..60}; do
  if docker exec ninja-validation-db mysqladmin ping -h 127.0.0.1 --silent; then break; fi
  sleep 2
done
docker exec ninja-validation-db mysqladmin ping -h 127.0.0.1 --silent
docker exec -i ninja-validation-app bash -se <<'VERIFY'
export APP_ENV=testing APP_URL=http://ninja.test NINJA_ENVIRONMENT=selfhost MULTI_DB_ENABLED=false
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export DB_CONNECTION=mysql DB_HOST=ninja-validation-db DB_DATABASE=ninja DB_USERNAME=ninja DB_PASSWORD=validation-only
export DB_HOST1="$DB_HOST" DB_DATABASE1="$DB_DATABASE" DB_USERNAME1="$DB_USERNAME" DB_PASSWORD1="$DB_PASSWORD"
export CACHE_STORE=array CACHE_DRIVER=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array SCOUT_DRIVER=null
php artisan migrate --force
php artisan db:seed --force
vendor/bin/phpunit tests/Unit/IntegrateCore/ConsultingHoursTest.php tests/Unit/IntegrateCore/VendorSupportRoutingTest.php tests/Unit/TaskRepositoryBulkUpdateTest.php
vendor/bin/phpunit --filter 'testBulkClientReassignmentMovesLegacyConsultingHoursViaApi|testTaskConsultingHoursSnapshotDoesNotRunClientFileSync|testMergeRetainsAlreadyChargedTaskTimeInCombinedBalance' tests/Feature
VERIFY
