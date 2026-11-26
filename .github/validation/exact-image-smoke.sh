#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
exec 3>&1
exec >/dev/null 2>&1

image=ghcr.io/integratecore/invoiceninja@sha256:01391692ba712ba69ddb59044bf4661dd6a967590cc5f1bf119aadba12e75210
expected_config=sha256:db8b1ca042aff81e0697d2947d58692520f4a18d7c77d19ea969ee2041afe2f1
network="candidate-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
database="$network-db"
smoke="$network-app"
stage="temporary Docker configuration"
docker_config=
cleanup() {
    docker rm -fv "$smoke" "$database" || true
    docker network rm "$network" || true
    if [[ -n $docker_config ]]; then rm -rf -- "$docker_config"; fi
}
trap cleanup EXIT
trap 'printf "Failed: %s\n" "$stage" >&3' ERR
docker_config=$(mktemp -d "$RUNNER_TEMP/ghcr.XXXXXXXX")
export DOCKER_CONFIG="$docker_config"

stage="read-only GHCR authentication"
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io --username "$GITHUB_ACTOR" --password-stdin
unset GHCR_TOKEN
stage="exact registry manifest and config"
docker manifest inspect "$image" | jq -e --arg config "$expected_config" '.config.digest == $config'
stage="pull by immutable digest and verify local config"
docker pull --platform linux/amd64 "$image"
test "$(docker image inspect --format '{{.Id}}' "$image")" = "$expected_config"
rm -rf -- "$docker_config"
unset DOCKER_CONFIG
docker_config=
printf 'Exact immutable manifest and config: verified\n' >&3

stage="disposable MySQL startup"
docker network create --internal "$network"
db_secret=$(openssl rand -hex 24)
db_root_secret=$(openssl rand -hex 24)
app_key=base64:$(openssl rand -base64 32)
docker run -d --network "$network" --name "$database" --network-alias mysql \
    -e MYSQL_ROOT_PASSWORD="$db_root_secret" -e MYSQL_DATABASE=ninja \
    -e MYSQL_USER=ninja -e MYSQL_PASSWORD="$db_secret" --tmpfs /var/lib/mysql mysql:8.0
wait_db() {
    local attempt
    for ((attempt=0; attempt<60; attempt++)); do
        if docker exec "$database" sh -c \
            'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -h127.0.0.1 -uroot -e "SELECT 1" ninja'; then
            return 0
        fi
        sleep 2
    done
    return 1
}
wait_db
stage="verify the MySQL schema is empty before startup"
db_tables=$(docker exec "$database" sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -h127.0.0.1 -uroot -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" ninja')
test "$db_tables" = 0
printf 'Disposable MySQL schema starts empty\n' >&3

stage="normal image entrypoint"
docker run -d --pull never --network "$network" --name "$smoke" \
    -e APP_ENV=production -e APP_KEY="$app_key" -e APP_URL=http://ninja.test \
    -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_DATABASE=ninja -e DB_USERNAME=ninja \
    -e DB_PASSWORD="$db_secret" -e DB_DATABASE3=ninja -e MULTI_DB_ENABLED=false \
    -e NINJA_ENVIRONMENT=selfhost -e CACHE_DRIVER=array -e CACHE_STORE=array \
    -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array \
    -e SENTRY_SAMPLE_RATE=0 -e SCOUT_DRIVER=null "$image"

stage="normal entrypoint HTTP health"
wait_health() {
    local attempt
    for ((attempt=0; attempt<60; attempt++)); do
        if docker exec "$smoke" curl -fsS --max-time 3 http://127.0.0.1/health |
            jq -e '.status == "ok"'; then
            return 0
        fi
        sleep 3
    done
    return 1
}
wait_health
printf 'Normal entrypoint HTTP /health: ready (status=ok)\n' >&3

stage="migrations applied with none pending"
status=$(docker exec "$smoke" php artisan migrate:status --no-ansi)
[[ $status == *Ran* && $status != *Pending* ]]
printf 'Fresh MySQL migrations: complete (none pending)\n' >&3
