#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"

reject() {
    if ( "$@" ) >/dev/null 2>&1; then
        fail "Expected rejection: $*"
    fi
}

# Exercise the real token validation and privacy gate without real credentials or network.
curl() {
    local header
    [[ $# == 11 && $1 == -q && $2 == -fs && $3 == --max-time && $4 == 20 &&
        $5 == --header && $6 == @- && $7 == --header &&
        $8 == 'Accept: application/vnd.github+json' && $9 == --write-out &&
        ${10} == '\n%{http_code}' &&
        ${11} == https://api.github.com/orgs/IntegrateCore/packages/container/invoiceninja ]] || return 2
    IFS= read -r header && [[ $header == "Authorization: Bearer $fixture_token" ]] || return 2
    if IFS= read -r header || [[ -n $header ]]; then return 2; fi
    printf '%s\n%s' "$fixture_package" "$fixture_http"
    return "$fixture_curl_exit"
}
fixture_private='{"name":"invoiceninja","package_type":"container","owner":{"login":"IntegrateCore"},"visibility":"private"}'
fixture_package=$fixture_private fixture_http=200 fixture_curl_exit=0
for fixture_token in ghs_legacy0123 'eyJ-a_b.eyJ-c_d.sig-n_ature' 'ghs_opaque-0123'; do
    require_private_package "$fixture_token"
done
for fixture_token in '' ' ' ' valid' 'valid ' $'valid\t' $'valid\r' $'valid\nX-Injected: yes' \
    $'valid\x1b' 'valid"quote' 'valid\escape' 'valid:header' 'valid=option' 'valid/config'; do
    if message=$(require_private_package "$fixture_token" 2>&1) ||
        [[ $message != 'Expected an existing GitHub package token' ]]; then
        fail "Malformed credential was not rejected before the metadata request"
    fi
done
fixture_token=ghs_fixture
fixture_http=403 reject require_private_package "$fixture_token"
fixture_http=302 reject require_private_package "$fixture_token"
fixture_curl_exit=22 reject require_private_package "$fixture_token"
fixture_package=invalid reject require_private_package "$fixture_token"
fixture_package="$fixture_private $fixture_private" reject require_private_package "$fixture_token"
for change in '.visibility="public"' 'del(.visibility)' '.name="wrong"' \
    '.package_type="npm"' '.owner.login="wrong"' 'del(.owner)'; do
    fixture_package=$(jq "$change" <<< "$fixture_private") reject require_private_package "$fixture_token"
done

php_base=sha256:6d2c08c2ce0548e9bc55e21aab68f5a2613e635e4225fdea84290459728995e7
runtime_profile
reject bash -c "$(declare -f fail runtime_profile); php_base=sha256:$(printf '%064d' 0); runtime_profile"

backend=173ff5a8362e49956b1bdb59197b286aa449e1c8
ui=98090aa2595fddca5d1e8433c4436d1292245ec7
config=sha256:$(printf '%064d' 1)
labels=$(jq -n --arg backend "$backend" --arg ui "$ui" --arg base "$php_base" '{
    "org.opencontainers.image.source":"https://github.com/IntegrateCore/invoiceninja",
    "org.opencontainers.image.revision":$backend,
    "net.integratecore.ui.revision":$ui,
    "org.opencontainers.image.base.digest":$base
}')
manifest=$(jq -n --arg config "$config" '{
    schemaVersion:2, mediaType:"application/vnd.docker.distribution.manifest.v2+json",
    config:{digest:$config}, layers:[{}]
}')

# Drive the real PHP predicate using a fake SAPI/extension inventory; no image or DB.
docker() {
    case "$*" in
        'image inspect '*) printf '%s\n' "$fixture_labels" ;;
        'run --rm --network none --entrypoint php fixture -r '*)
            php -r '
                namespace ReleaseFixture;
                const PHP_VERSION_ID = 80427;
                function extension_loaded($e) {
                    global $argv;
                    return $e !== $argv[3];
                }
                function phpversion($e) {
                    global $argv;
                    return $e === "zip" ? $argv[4] : ($e === $argv[5] ? "8.4.26" : PHP_VERSION);
                }
                function bcadd($a, $b, $scale) { return "3.00"; }
            '"const PHP_VERSION = '$fixture_php';$9" "${@:10}" \
                "$missing_extension" "$fixture_zip" "$stale_extension"
            ;;
        'run --rm --network none --entrypoint php-fpm fixture -v')
            printf 'PHP %s (fpm-fcgi)\n' "$fixture_fpm" ;;
        'run --rm --network none --entrypoint php-fpm fixture -t') return 0 ;;
        'run --rm --network none --entrypoint sh fixture -ec '*) return 0 ;;
        *) fail "Unexpected Docker call in regression fixture" ;;
    esac
}

fixture_php=8.4.27 fixture_zip=1.22.8 fixture_fpm=8.4.27
missing_extension= stale_extension=
verify_runtime fixture >/dev/null
missing_extension=zip reject verify_runtime fixture
fixture_zip=8.4.27 reject verify_runtime fixture
fixture_zip=1.22.7 reject verify_runtime fixture
fixture_php=8.4.26 reject verify_runtime fixture
stale_extension=pdo_mysql reject verify_runtime fixture
fixture_fpm=8.4.28 reject verify_runtime fixture

fixture_labels=$labels
verify_labels fixture
while IFS= read -r label; do
    fixture_labels=$(jq --arg key "$label" 'del(.[$key])' <<< "$labels") reject verify_labels fixture
    fixture_labels=$(jq --arg key "$label" '.[$key]="wrong"' <<< "$labels") reject verify_labels fixture
done < <(jq -r 'keys[]' <<< "$labels")

verify_manifest "$config" <<< "$manifest" >/dev/null
reject verify_manifest "sha256:$(printf '%064d' 2)" <<< "$manifest"
reject verify_manifest "$config" <<< "$(jq '.manifests=[]' <<< "$manifest")"
reject verify_manifest "$config" <<< "$manifest $manifest"
echo "Package privacy, runtime, provenance and registry manifest gates passed"
