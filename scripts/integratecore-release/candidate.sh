#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

[[ $# == 4 ]] || fail "Usage: candidate.sh BACKEND_SHA UI_SHA REGISTRY_USER NEW_HANDOFF.json"
backend=$1 ui=$2 registry_user=$3
sha "$backend" && sha "$ui" || fail "Use full lowercase commit SHAs"
output=$(realpath -m "$4")
[[ ! -e $output ]] || fail "Handoff already exists"
[[ ! -d /opt/invoiceninja-dev && ! -d /srv/invoiceninja ]] || fail "Do not build on an Invoice Ninja host"
[[ $(uname -s) == Linux && $(uname -m) == x86_64 ]] || fail "Use a Linux amd64 off-server runner"
node -e 'const [major, minor] = process.versions.node.split(".").map(Number); process.exit(major !== 22 || minor < 12 ? 1 : 0)' || fail "Node 22.12 or later in the 22 series required"
[[ $(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;') == 8.4 ]] || fail "PHP 8.4 required"

[[ -z ${DOCKER_HOST:-} || $DOCKER_HOST == unix://* ]] || fail "Use a local Docker daemon"
[[ $(docker context inspect --format '{{.Endpoints.docker.Host}}') == unix://* ]] ||
    fail "Use a local Docker context"

registry_token=${GHCR_TOKEN:-}
unset GHCR_TOKEN
if [[ -z $registry_token ]]; then
    read -rsp "Existing backend package writer token: " registry_token
    printf '\n' >&2
fi
[[ $registry_token =~ ^[A-Za-z0-9_]+$ ]] || fail "Expected an existing GitHub package token"
# Refuse to create a missing package or publish to a non-private one.
printf 'header = "Authorization: Bearer %s"\n' "$registry_token" |
    curl -q -fsS --max-time 20 --config - https://api.github.com/orgs/IntegrateCore/packages/container/invoiceninja |
    jq -e '.name == "invoiceninja" and .visibility == "private"' >/dev/null ||
    fail "Cannot verify the existing private backend package with this token"

work=$(mktemp -d)
run_id=$(openssl rand -hex 8)
network="invoice-release-$run_id"
database="$network-db"
smoke="$network-app"
candidate="$repository:candidate-${backend:0:12}-${ui:0:12}-$run_id"
cleanup() {
    docker rm -fv "$smoke" "$database" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
    docker image rm "$candidate" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

checkout() {
    local folder=$1 repo=$2 revision=$3
    git init -q "$folder"
    GIT_TERMINAL_PROMPT=0 git -C "$folder" -c credential.helper= fetch -q --depth 1 "$repo" "$revision"
    git -C "$folder" switch -q -c release-source FETCH_HEAD
    [[ $(git -C "$folder" rev-parse HEAD) == "$revision" ]] || fail "Source checkout mismatch"
}
checkout "$work/backend" https://github.com/IntegrateCore/invoiceninja.git "$backend"
checkout "$work/ui" https://github.com/IntegrateCore/invoiceninja-ui.git "$ui"
cd "$work/backend"
php_base=$(sed -nE 's/^FROM php:8\.4\.[0-9]+-fpm-bookworm@(sha256:[a-f0-9]{64}) AS php-runtime$/\1/p' Dockerfile)
digest "$php_base" && grep -Fxq 'FROM php-runtime AS app' Dockerfile ||
    fail "Expected the app to descend from the pinned PHP 8.4 runtime stage"

composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist
CYPRESS_INSTALL_BINARY=0 npm ci
rm -rf public/build
npm run build
(
    cd "$work/ui"
    cp .env.example .env
    sed -i -E '/^VITE_(IS_TEST|PUSHER_APP_KEY)=/d' .env
    printf '\nVITE_IS_TEST=false\nVITE_PUSHER_APP_KEY=f0111a02782708da651f\n' >> .env
    cp "$work/backend/vite.config.ts.react" vite.config.js
    CYPRESS_INSTALL_BINARY=0 npm ci
    npm run build
)
rm -f public/index.html
find public -maxdepth 1 -name 'bundle.*.js' -delete
test -s "$work/ui/dist/index.html"
cp -a "$work/ui/dist/." public/

docker build --platform linux/amd64 --pull --tag "$candidate" \
    --label org.opencontainers.image.source=https://github.com/IntegrateCore/invoiceninja \
    --label "org.opencontainers.image.revision=$backend" \
    --label "net.integratecore.ui.revision=$ui" \
    --label "org.opencontainers.image.base.digest=$php_base" .
# Check actual image lineage in addition to the Dockerfile and labels.
php_parent="docker.io/library/php@$php_base"
docker pull --platform linux/amd64 "$php_parent"
parent_layers=$(docker image inspect -f '{{json .RootFS.Layers}}' "$php_parent")
docker image inspect -f '{{json .RootFS.Layers}}' "$candidate" |
    jq -e --argjson base "$parent_layers" '($base | length) > 0 and .[0:($base | length)] == $base' >/dev/null ||
    fail "Built image does not descend from the pinned PHP image"
verify_labels "$candidate"
verify_runtime "$candidate"

# Keep test dependencies and database out of the published artifact.
docker network create "$network" >/dev/null
db_secret=$(openssl rand -hex 24)
app_key=base64:$(openssl rand -base64 32)
start_db() {
    local attempt
    docker run -d --network "$network" --name "$database" --network-alias mysql \
        -e MYSQL_ROOT_PASSWORD="$db_secret" -e MYSQL_DATABASE=ninja mysql:8.0 >/dev/null
    for ((attempt=0; attempt<60; attempt++)); do
        if docker exec "$database" sh -c \
            'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -h127.0.0.1 -uroot -e "SELECT 1" ninja' >/dev/null 2>&1; then
            return
        fi
        sleep 2
    done
    fail "Disposable MySQL failed to start"
}
app_env=(
    --network "$network" -e APP_KEY="$app_key" -e APP_URL=http://localhost
    -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_DATABASE=ninja -e DB_USERNAME=root
    -e DB_PASSWORD="$db_secret" -e DB_DATABASE3=ninja -e MULTI_DB_ENABLED=false
    -e NINJA_ENVIRONMENT=selfhost -e CACHE_DRIVER=file -e CACHE_STORE=file
    -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array
    -e SENTRY_SAMPLE_RATE=0
)
start_db
docker run --rm "${app_env[@]}" -e APP_ENV=testing --entrypoint bash "$candidate" -euc '
    composer install --no-interaction --no-progress --prefer-dist --no-scripts
    php artisan package:discover
    php artisan migrate --force
    php artisan db:seed --force
    vendor/bin/phpunit --fail-on-empty-test-suite tests/Unit/TaskRoundingTest.php
    vendor/bin/phpunit --fail-on-empty-test-suite \
        --filter "testPutRejectsOverlapAfterFirstTimeLogPair|testPutAllowsFinalRunningTimeLogEntry" \
        tests/Feature/TaskApiTest.php
'

mkdir -m 700 "$work/docker-auth"
registry=(docker --config "$work/docker-auth")
printf '%s' "$registry_token" | "${registry[@]}" login ghcr.io --username "$registry_user" --password-stdin
unset registry_token
"${registry[@]}" push "$candidate" | tee "$work/push.log"
published=$(sed -nE 's/^.*: digest: (sha256:[a-f0-9]{64}) size: [0-9]+$/\1/p' "$work/push.log")
digest "$published" || fail "Could not resolve pushed digest"
image="$repository@$published"
"${registry[@]}" pull "$image"
[[ $(docker image inspect -f '{{.Id}}' "$image") == "$(docker image inspect -f '{{.Id}}' "$candidate")" ]] ||
    fail "Published digest does not match tested image"
verify_labels "$image"
verify_runtime "$image"

docker rm -fv "$database" >/dev/null
start_db
docker run -d "${app_env[@]}" --name "$smoke" -e APP_ENV=production "$image" >/dev/null
wait_health "$smoke" || fail "Published digest failed fresh DB startup/HTTP health check"
migration_status=$(docker exec "$smoke" php artisan migrate:status --no-ansi)
[[ -n $migration_status && $migration_status != *Pending* ]] || fail "Smoke database did not finish migrations"
(
    umask 077
    set -o noclobber
    jq -n --arg b "$backend" --arg u "$ui" --arg i "$image" --arg p "$php_base" \
        '{backend:$b,image:$i,php_base:$p,ui:$u,version:1}' > "$output"
)
printf 'Verified release handoff: %s\n' "$output"
