#!/usr/bin/env bash
repository=ghcr.io/integratecore/invoiceninja
fail() { echo "$*" >&2; exit 1; }
sha() { [[ $1 =~ ^[0-9a-f]{40}$ ]]; }
digest() { [[ $1 =~ ^sha256:[0-9a-f]{64}$ ]]; }

read_handoff() {
    handoff=$(jq -ce 'select(keys == ["backend","image","php_base","ui","version"] and .version == 1)' "$1")
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

verify_runtime() {
    local version
    version=$(docker run --rm --network none --entrypoint php "$1" -r '
        if (PHP_VERSION_ID < 80427 || PHP_VERSION_ID >= 80500) exit(1);
        foreach (["bcmath","exif","gd","intl","soap","Zend OPcache","pdo_mysql","zip"] as $e)
            if (!extension_loaded($e) || phpversion($e) !== PHP_VERSION) exit(1);
        if (!extension_loaded("imagick") || bcadd("1","2",2) !== "3.00") exit(1);
        echo PHP_VERSION;
    ') || return 1
    docker run --rm --network none --entrypoint php-fpm "$1" -v | grep -F "PHP $version" || return 1
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
