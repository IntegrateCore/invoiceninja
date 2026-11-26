#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

[[ $# -ge 3 ]] || fail "Usage: host.sh staging|production preflight|apply|accept|receipt HANDOFF [ACCEPTANCE_OR_RECEIPT]"
target=$1 action=$2
read_handoff "$3"
case "$target" in
    staging) root=/opt/invoiceninja-dev; project=invoiceninja-dev; reserve=4194304 ;;
    production) root=/srv/invoiceninja; project=invoiceninja; reserve=6291456 ;;
    *) fail "Unknown environment" ;;
esac
case "$action" in
    preflight|apply) ;;
    accept|receipt) [[ $target == staging ]] || fail "Acceptance and receipts are staging-only" ;;
    *) fail "Unknown action" ;;
esac
if [[ $target == production && $action == apply ]]; then
    [[ $# == 4 ]] || fail "Production requires a staging receipt"
    validate_receipt "$4" || fail "Production requires matching automated and manual staging evidence"
elif [[ $action == accept ]]; then
    [[ $# == 4 ]] || fail "Acceptance requires an operator-completed JSON record"
    acceptance=$(jq -ces --arg image "$image" 'select(length == 1) | .[0] | select('"$acceptance_filter"')' "$4") ||
        fail "Invalid acceptance: provide matching digest, three passes and a REL review reference"
else
    [[ $# == 3 ]] || fail "Unexpected arguments"
fi

cd "$root"
[[ $(uname -m) == x86_64 ]] || fail "Candidate requires amd64"
umask 077
exec 9<.
flock -n 9 || fail "Another release is running on this host"
base=docker-compose.yml
override=docker-compose.override.yml
app="$project-invoiceninja-1"
db="$project-mysql-1"
release="$root/releases/${image##*:}"
marker='# Managed by IntegrateCore immutable release'
compose=(docker compose --project-name "$project" -f "$base")
if [[ -e $override || -L $override ]]; then
    [[ -f $override && ! -L $override ]] && grep -Fxq "$marker" "$override" ||
        fail "Unmanaged Compose override; reconcile it before using this script"
    compose+=(-f "$override")
fi
for other in compose.yaml compose.yml compose.override.yaml compose.override.yml docker-compose.yaml docker-compose.override.yaml; do
    [[ ! -e $other ]] || fail "Ambiguous Compose configuration: $other"
done

assert_live() {
    local id
    id=$(docker inspect --format '{{.Image}}' "$app")
    [[ $id == "$(docker image inspect --format '{{.Id}}' "$image")" ]] &&
        [[ $(docker inspect --format '{{.Config.Image}}' "$app") == "$image" ]] &&
        verify_labels "$image" && healthy "$app"
}
container_id() { docker inspect --format '{{.Id}}' "$app"; }
if [[ $action == accept || $action == receipt ]]; then
    [[ ! -e releases/recovery-required && -f $release/automatic.json ]] &&
        assert_live && migrations_ready "$app" || fail "Staging has not verified this digest"
    automatic=$(jq -ce --arg id "$(container_id)" 'select(.container == $id)' "$release/automatic.json") ||
        fail "Staging container changed; rerun staging apply and recheck acceptance"

    if [[ $action == accept ]]; then
        pending=$(mktemp "$release/manual.XXXXXXXX")
        jq -n --argjson automatic "$automatic" --argjson record "$acceptance" \
            '{automatic:$automatic,record:$record}' > "$pending"
        mv -T "$pending" "$release/manual.json"
        echo "Recorded operator acceptance for $image"
    else
        [[ -f $release/manual.json ]] || fail "Manual staging acceptance has not been recorded"
        pending=$(mktemp "$release/receipt.XXXXXXXX")
        trap 'rm -f "$pending"' EXIT
        jq -n --argjson handoff "$handoff" --argjson automatic "$automatic" --slurpfile manual "$release/manual.json" \
            '{version:2,environment:"staging",handoff:$handoff,automatic:$automatic,manual:$manual[0]}' > "$pending"
        validate_receipt "$pending" || fail "Manual staging acceptance is missing or stale"
        cat "$pending"
    fi
    exit
fi

available() { df -Pk "$1" | awk 'NR == 2 {print $4}'; }
[[ $(available "$root") -ge $reserve ]] || fail "Insufficient free space in Compose filesystem"
docker_root=$(docker info --format '{{.DockerRootDir}}')
[[ $(available "$docker_root") -ge $reserve ]] || fail "Insufficient Docker disk space"
[[ $(awk '/^MemAvailable:/ {print $2}' /proc/meminfo) -ge 786432 ]] ||
    fail "At least 768 MiB available RAM is required"
docker exec "$db" sh -ec '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -e "SELECT 1" "$MYSQL_DATABASE"
' >/dev/null || fail "Database preflight query failed"
[[ $(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$app") == "$root" ]] ||
    fail "Live Compose project does not match release destination"
healthy "$app" || fail "Existing app is not healthy"
"${compose[@]}" config --quiet
[[ -r .env && -r $base ]] || fail "Missing deployment configuration"
echo "Preflight passed: $target; digest $image"
[[ $action == apply ]] || exit 0

[[ ! -e releases/recovery-required ]] || fail "Resolve the previous release recovery first"
if assert_live; then
    echo "Already healthy on $image"
else
    # No credentials are sent to the host. Use its existing read-only GHCR login.
    docker pull "$image"
    verify_labels "$image" || fail "Candidate labels do not match the handoff"
    verify_runtime "$image" || fail "Candidate runtime check failed"
    [[ $(available "$root") -ge $reserve && $(available "$docker_root") -ge $reserve ]] ||
        fail "Disk reserve insufficient after pull"
    mkdir -p "$release"
    backup=$(mktemp -d "$release/attempt.XXXXXXXX")
    cp -p "$base" "$backup/compose.before.yml"
    cp -p .env "$backup/env.before"
    if [[ -f $override ]]; then cp -p "$override" "$backup/override.before.yml"; fi
    docker inspect -f '{{.Image}}' "$app" > "$backup/image-id.before"
    rollback="integratecore/invoiceninja:rollback-${backup##*.}"
    docker tag "$(cat "$backup/image-id.before")" "$rollback"
    printf '%s\n' "$marker" 'services:' '  invoiceninja:' "    image: $image" > "$backup/override.next.yml"
    printf '%s\n' "$marker" 'services:' '  invoiceninja:' "    image: $rollback" > "$backup/rollback.yml"
    next=(docker compose --project-name "$project" -f "$base" -f "$backup/override.next.yml")
    "${next[@]}" config --quiet

    # Stop writes before the dump. A failed migration may require restoring the
    # DB; never automatically start older code against a partially migrated DB.
    printf '%s\n' "$backup" > releases/recovery-required
    on_error() {
        docker stop "$app" >/dev/null 2>&1 || true
        echo "Release interrupted; recovery instructions and backups: $backup" >&2
    }
    trap on_error EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    "${compose[@]}" stop invoiceninja
    docker exec "$db" sh -ec '
        MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --quick \
            --no-tablespaces --routines --triggers "$MYSQL_DATABASE"
    ' > "$backup/database-before.sql"
    test -s "$backup/database-before.sql"
    grep -q '^-- Dump completed' "$backup/database-before.sql"
    [[ $(available "$root") -ge $reserve ]] || fail "Disk reserve insufficient after dump"

    # Place the digest where normal Compose operations will reuse it.
    cp "$backup/override.next.yml" "$override.next"
    mv -T "$override.next" "$override"
    installed=(docker compose --project-name "$project" -f "$base" -f "$override")
    touch "$backup/candidate-start-attempted"
    "${installed[@]}" up -d --no-deps --no-build --pull never invoiceninja
    if ! wait_health "$app" || ! assert_live || ! migrations_ready "$app"; then
        "${installed[@]}" stop invoiceninja
        fail "New app failed verification; left stopped for recovery"
    fi
    rm releases/recovery-required
    trap - EXIT INT TERM
    echo "Release healthy: $target; recovery materials: $backup"
fi

if [[ $target == staging ]]; then
    migrations_ready "$app" || fail "Staging migrations are not ready"
    mkdir -p "$release"
    result=$(mktemp "$release/automatic.XXXXXXXX")
    jq -n --arg id "$(container_id)" --arg checked "$(date -u +%FT%TZ)" \
        '{container:$id,checked_at:$checked,health:"pass",migrations:"pass"}' > "$result"
    mv -T "$result" "$release/automatic.json"
    rm -f "$release/manual.json"
fi
