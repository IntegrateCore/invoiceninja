#!/usr/bin/env bash
set -Eeuo pipefail
stage=packages
trap 'printf "Probe failed at: %s\n" "$stage" >&2' ERR
work=$1
umask 077
packages=https://download.docker.com/linux/ubuntu/dists/noble/pool/stable/amd64
fetch_package() {
    local filename=$1 checksum=$2
    printf "Fetching pinned package: %s\n" "$filename"
    curl -q -fsS --max-time 35 "$packages/$filename" -o "$work/$filename"
    printf '%s  %s\n' "$checksum" "$work/$filename" | sha256sum -c --status
    dpkg-deb -x "$work/$filename" "$work/binaries"
}
fetch_package containerd.io_2.2.1-1~ubuntu.24.04~noble_amd64.deb 95e5464da24724dafc576075401c1e781f50f713f62237ee2ae93e220e3e1273
fetch_package docker-ce_29.1.3-1~ubuntu.24.04~noble_amd64.deb 010dee76e0c1833e1e538b4897e914a2d2d23972acb8798baf056565202a06ab
fetch_package docker-ce-cli_29.1.3-1~ubuntu.24.04~noble_amd64.deb 06e43beef08b66d39c24d38bcbe2e64889a59f6228ff715e1a27f967a3aa37cb
bin="$work/binaries/usr/bin"
printf '{}\n' > "$work/daemon.json"
mkdir -m 700 "$work/client"
registry_name=registry-fixture.invalid
cert_dir=/etc/docker/certs.d/$registry_name
stop_process() {
    if [[ ! -s $1 ]]; then return; fi
    local pid
    pid=$(cat "$1")
    sudo kill "$pid" 2>/dev/null || return 0
    for _ in $(seq 1 12); do
        if ! sudo kill -0 "$pid" 2>/dev/null; then return 0; fi
        sleep .25
    done
    sudo kill -KILL "$pid" 2>/dev/null || true
}
cleanup() {
    stop_process "$work/daemon.pid"
    stop_process "$work/registry.pid"
    stop_process "$work/containerd.pid"
    if [[ -e "$work/hosts.original" ]]; then sudo cp "$work/hosts.original" /etc/hosts; fi
    if [[ -e "$work/cert-installed" ]]; then sudo rm -f "$cert_dir/ca.crt"; sudo rmdir "$cert_dir"; fi
}
trap cleanup EXIT

stage=containerd-startup
printf 'version = 3\n' > "$work/containerd.toml"
sudo env PATH="$bin:$PATH" sh -c '
    echo "$" > "$1/containerd.pid"
    exec "$2/containerd" --config "$1/containerd.toml" --root "$1/ct-data" \
        --state "$1/ct-state" --address "$1/containerd.sock"
' sh "$work" "$bin" > "$work/containerd.log" 2>&1 &
for _ in $(seq 1 15); do
    if [[ -S "$work/containerd.sock" ]]; then break; fi
    sleep 1
done
if [[ ! -S "$work/containerd.sock" ]]; then tail -n 18 "$work/containerd.log"; exit 1; fi
stage=dockerd-startup
sudo env PATH="$bin:$PATH" "$bin/dockerd" \
    --containerd "$work/containerd.sock" \
    --config-file "$work/daemon.json" --no-proxy "$registry_name,127.0.0.1" --host "unix://$work/docker.sock" \
    --data-root "$work/data" --exec-root "$work/exec" --pidfile "$work/daemon.pid" \
    --feature containerd-snapshotter=true --bridge none \
    --iptables=false --ip6tables=false --ip-forward=false --userland-proxy=false \
    > "$work/daemon.log" 2>&1 &
d() {
    timeout -k 3s 22s sudo env DOCKER_CONFIG="$work/client" "$bin/docker" --host "unix://$work/docker.sock" "$@"
}
for _ in $(seq 1 12); do
    if [[ -S "$work/docker.sock" ]] && d info >/dev/null 2>&1; then break; fi
    sleep 1
done
if ! d version --format '{{json .Server}}' | jq -e '
    .Version=="29.1.3" and .GitCommit=="fbf3ed2" and .Os=="linux" and .Arch=="amd64"
    and any(.Components[]; .Name=="containerd" and .Version=="v2.2.1" and .Details.GitCommit=="dea7da592f5d1d2b7755e3a161be07f43fad8f75")
    and any(.Components[]; .Name=="runc" and .Version=="1.3.4" and .Details.GitCommit=="v1.3.4-0-gd6d73eb8")' >/dev/null; then
    echo "Exact staging engine/component build did not start or match"
    d version --format '{{json .Server}}' | jq '{Version,GitCommit,Os,Arch,Components:[.Components[]|{Name,Version,commit:.Details.GitCommit}]}' || true
    tail -n 12 "$work/daemon.log"
    exit 1
fi
d info --format '{{json .}}' | jq -e '
    .Driver=="overlayfs" and .CgroupVersion=="2"
    and any(.DriverStatus[]; .==["driver-type","io.containerd.snapshotter.v1"])' >/dev/null
echo 'Matched staging Docker 29.1.3, containerd 2.2.1, runc 1.3.4, overlayfs snapshotter, linux/amd64, cgroup v2'

stage=fixture-assembly
printf '%s\n' 'int main(void) { return 0; }' | gcc -x c -static -s -Os -o "$work/fixture" -
python3 "$work/oci-import-fixture.py" create "$work"
repo=$(jq -er .repo "$work/expected.json")
digest=$(jq -er .manifest "$work/expected.json")
config=$(jq -er .config "$work/expected.json")
moved_config=$(jq -er .moved_config "$work/expected.json")
ref="$repo@$digest"
identity() {
    [[ $1 == "$ref" ]] || return 1
    d image inspect "$1" 2>/dev/null | jq -e --arg ref "$ref" --arg config "$config" '
        length==1 and .[0].Id==$config and .[0].Os=="linux" and .[0].Architecture=="amd64"
        and (.[0].RepoDigests | index($ref)!=null)' >/dev/null
}
stage=offline-load
offline=false
for kind in tag digest; do
    if d image load -i "$work/oci-$kind.tar" >"$work/load-$kind.log" 2>&1; then
        if identity "$ref"; then
            printf 'Docker load (%s descriptor): original repository digest and config preserved\n' "$kind"
            offline=true
        else
            printf 'Docker load (%s descriptor): original repository digest not resolvable with required evidence\n' "$kind"
        fi
    else
        printf 'Docker load (%s descriptor): archive not accepted\n' "$kind"
        tail -n 3 "$work/load-$kind.log"
    fi
done

if [[ $offline == false ]]; then
    stage=loopback-registry
    echo "Starting the synthetic loopback TLS fixture"
    [[ ! -e "$cert_dir" ]]
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 1 -subj "/CN=$registry_name" \
        -addext "subjectAltName=DNS:$registry_name" -keyout "$work/tls.key" -out "$work/tls.crt" >/dev/null 2>&1
    sudo install -d -m 755 "$cert_dir"
    touch "$work/cert-installed"
    sudo install -m 644 "$work/tls.crt" "$cert_dir/ca.crt"
    cp /etc/hosts "$work/hosts.original"
    printf '127.0.0.1 %s\n' "$registry_name" | sudo tee -a /etc/hosts >/dev/null
    sudo sh -c 'echo "$$" > "$1/registry.pid"; exec python3 "$1/oci-import-fixture.py" serve "$1"' sh "$work" &
    for _ in $(seq 1 15); do
        if curl -q -fsS --noproxy '*' --resolve "$registry_name:443:127.0.0.1" --cacert "$work/tls.crt" \
            --max-time 2 "https://$registry_name/v2/" >/dev/null 2>&1; then break; fi
        sleep 1
    done
    stage=loopback-listener-check
    sudo ss -Hltpn 'sport = :443' | awk '
        {n++; if ($4 != "127.0.0.1:443") bad=1}
        END {exit n!=1 || bad}'
    curl -q -fsS --noproxy '*' --resolve "$registry_name:443:127.0.0.1" --cacert "$work/tls.crt" \
        --max-time 5 "https://$registry_name/v2/invoiceninja/manifests/$digest" -o "$work/received-manifest"
    cmp "$work/received-manifest" "$work/layout/blobs/sha256/${digest#sha256:}"
    test "sha256:$(sha256sum "$work/received-manifest" | cut -d' ' -f1)" = "$digest"
    echo "Loopback peer and original manifest bytes verified"
    stage=loopback-docker-pull
    d pull --platform linux/amd64 "$ref" >/dev/null
    identity "$ref"
    echo 'Loopback TLS registry: received manifest byte-for-byte identical; canonical digest and config resolve'
    stage=moved-tag-fixture
    d pull --platform linux/amd64 "$repo:candidate" >/dev/null
else
    d image load -i "$work/oci-moved.tar" >/dev/null
fi
test "$(d image inspect "$repo:candidate" --format '{{.Id}}')" = "$moved_config"
echo 'Moved tag resolves to a different config, not the approved digest'
if identity "$repo:candidate" || identity "$repo:movable"; then
    echo 'Unexpectedly accepted a movable reference'
    exit 1
fi
echo 'Tag-only evidence rejected'

stage=runtime-identity
bad="sha256:$(printf '%064d' 0)"
if d image inspect "$repo@$bad" >/dev/null 2>&1 || d run --rm --network none --pull never "$repo@$bad" >/dev/null 2>&1; then
    echo 'Unexpectedly accepted a wrong manifest digest'
    exit 1
fi
identity "$ref"
cid=$(d create --network none --pull never "$ref")
d container inspect "$cid" | jq -e --arg config "$config" --arg ref "$ref" '
    length==1 and .[0].Image==$config and .[0].Config.Image==$ref' >/dev/null
d start -a "$cid" >/dev/null
test "$(d container inspect "$cid" --format '{{.State.ExitCode}}')" = 0
d rm "$cid" >/dev/null
echo 'Exact repo@digest created and ran with --pull never; runtime image and config identities matched; wrong digest rejected'
