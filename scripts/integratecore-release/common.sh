#!/usr/bin/env bash
repository=ghcr.io/integratecore/invoiceninja
fail() { echo "$*" >&2; exit 1; }
sha() { [[ $1 =~ ^[0-9a-f]{40}$ ]]; }
digest() { [[ $1 =~ ^sha256:[0-9a-f]{64}$ ]]; }

# Send one literal header via stdin, never via curl argv or curl configuration.
require_private_package() {
    local LC_ALL=C token=$1 response
    [[ $token =~ ^[A-Za-z0-9_.-]+$ ]] || fail "Expected an existing GitHub package token"
    response=$(
        printf 'Authorization: Bearer %s\n' "$token" |
            curl -q -fs --max-time 20 --header @- \
                --header 'Accept: application/vnd.github+json' \
                --write-out '\n%{http_code}' \
                https://api.github.com/orgs/IntegrateCore/packages/container/invoiceninja
    ) || fail "Cannot verify the existing private backend package with this token"
    [[ ${response##*$'\n'} == 200 ]] &&
        jq -se 'length == 1 and (.[0] |
            .name == "invoiceninja" and .package_type == "container"
            and (.owner.login | ascii_downcase) == "integratecore"
            and .visibility == "private")' <<< "${response%$'\n'*}" >/dev/null 2>&1 ||
        fail "Cannot verify the existing private backend package with this token"
}

read_handoff() {
    handoff=$(jq -ces 'select(length == 1) | .[0] |
        select(type == "object" and keys == ["backend","image","php_base","ui","version"] and .version == 1)' "$1") ||
        fail "Invalid handoff document"
    backend=$(jq -r .backend <<< "$handoff")
    ui=$(jq -r .ui <<< "$handoff")
    image=$(jq -r .image <<< "$handoff")
    php_base=$(jq -r .php_base <<< "$handoff")
    sha "$backend" && sha "$ui" && digest "$php_base" || fail "Invalid source or PHP lineage"
    [[ $image == "$repository@"* ]] && digest "${image#*@}" || fail "Invalid image reference"
}

verify_labels() {
    local labels
    labels=$(docker image inspect --format '{{json .Config.Labels}}' "$1")
    jq -e --arg b "$backend" --arg u "$ui" --arg p "$php_base" '
        .["org.opencontainers.image.source"] == "https://github.com/IntegrateCore/invoiceninja"
        and .["org.opencontainers.image.revision"] == $b
        and .["net.integratecore.ui.revision"] == $u
        and .["org.opencontainers.image.base.digest"] == $p
    ' <<< "$labels" >/dev/null
}

# Reviewed Dockerfile base. The PHP source bundled with this image declares
# ZIP 1.22.8 in ext/zip/php_zip.h; that version is independent of PHP_VERSION.
runtime_profile() {
    [[ $php_base == sha256:6d2c08c2ce0548e9bc55e21aab68f5a2613e635e4225fdea84290459728995e7 ]] ||
        fail "PHP base has no reviewed runtime profile"
    expected_php=8.4.27
    expected_zip=1.22.8
}

verify_manifest() {
    local config=$1
    jq -es --arg config "$config" '
        length == 1 and (.[0] |
            .schemaVersion == 2
            and (.mediaType == "application/vnd.docker.distribution.manifest.v2+json"
                or .mediaType == "application/vnd.oci.image.manifest.v1+json")
            and .config.digest == $config and (has("manifests") | not)
            and (.layers | type == "array" and length > 0))
    '
}

verify_runtime() {
    runtime_profile
    docker run --rm --network none --entrypoint php "$1" -r '
        if (PHP_VERSION_ID < 80427 || PHP_VERSION_ID >= 80500 || PHP_VERSION !== $argv[1]) {
            fwrite(STDERR, "Unexpected PHP CLI version\n"); exit(1);
        }
        foreach (["bcmath","exif","gd","intl","soap","Zend OPcache","pdo_mysql"] as $e) {
            if (!extension_loaded($e) || phpversion($e) !== PHP_VERSION) {
                fwrite(STDERR, "Missing or mismatched PHP extension: $e\n"); exit(1);
            }
        }
        if (!extension_loaded("zip") || phpversion("zip") !== $argv[2]) {
            fwrite(STDERR, "Missing or mismatched ZIP extension\n"); exit(1);
        }
        if (!extension_loaded("imagick") || bcadd("1","2",2) !== "3.00") exit(1);
    ' -- "$expected_php" "$expected_zip" || return 1
    docker run --rm --network none --entrypoint php-fpm "$1" -v |
        grep -F "PHP $expected_php (fpm-fcgi)" || return 1
    docker run --rm --network none --entrypoint php-fpm "$1" -t || return 1
    docker run --rm --network none --entrypoint sh "$1" -ec '
        test -s public/build/manifest.json
        test -s public/index.html
        test -n "$(find public -maxdepth 1 -name "bundle.*.js" -print -quit)"
    '
}

healthy() {
    docker exec "$1" curl -fsS --max-time 3 http://127.0.0.1/health 2>/dev/null |
        jq -e '.status == "ok"' >/dev/null 2>&1
}
wait_health() {
    local attempt
    for ((attempt=0; attempt<60; attempt++)); do
        healthy "$1" && return 0
        sleep 3
    done
    return 1
}

# Only a short review reference is accepted; no free-form notes or credentials.
acceptance_filter='
    type == "object" and keys == ["image","login","portal","review","workflows"]
    and .image == $image
    and .login == "pass" and .portal == "pass" and .workflows == "pass"
    and (.review | type == "string" and test("^REL-[0-9]{1,12}$"))
'

validate_receipt() {
    jq -es --argjson release "$handoff" --arg image "$image" '
        length == 1 and (.[0] |
            .version == 2 and .environment == "staging" and .handoff == $release
            and (.automatic.container | type == "string" and test("^[0-9a-f]{64}$"))
            and .automatic.health == "pass" and .automatic.migrations == "pass"
            and (.automatic.checked_at | type == "string")
            and .manual.automatic == .automatic
            and (.manual.record | ('"$acceptance_filter"'))
        )
    ' "$1" >/dev/null
}

migrations_ready() {
    local status
    status=$(docker exec "$1" php artisan migrate:status --no-ansi) || return 1
    [[ $status == *Ran* && $status != *Pending* ]]
}
