"""Create tiny synthetic images and serve their exact bytes on loopback only."""

import gzip
import hashlib
import http.server
import json
from pathlib import Path
import ssl
import sys
import tarfile

REGISTRY = "registry-fixture.invalid"
REPO = "invoiceninja"
IMAGE = f"{REGISTRY}/{REPO}"
MANIFEST = "application/vnd.oci.image.manifest.v1+json"
CONFIG = "application/vnd.oci.image.config.v1+json"
LAYER = "application/vnd.oci.image.layer.v1.tar+gzip"


def sha(data):
    return "sha256:" + hashlib.sha256(data).hexdigest()


def encode(value):
    return json.dumps(value, indent=2).encode()


def create(root):
    layout = root / "layout"
    blobs = layout / "blobs/sha256"
    blobs.mkdir(parents=True)
    (layout / "oci-layout").write_bytes(encode({"imageLayoutVersion": "1.0.0"}))
    with tarfile.open(root / "layer.tar", "w") as tar:
        tar.add(root / "fixture", arcname="fixture")
    uncompressed = (root / "layer.tar").read_bytes()

    def descriptor(data, media_type):
        digest = sha(data)
        (blobs / digest.split(":")[1]).write_bytes(data)
        return {"mediaType": media_type, "digest": digest, "size": len(data)}

    layer = descriptor(gzip.compress(uncompressed, mtime=0), LAYER)

    def image(label):
        config = descriptor(encode({
            "architecture": "amd64", "os": "linux",
            "config": {"Entrypoint": ["/fixture"], "Labels": {"probe.fixture": label}},
            "rootfs": {"type": "layers", "diff_ids": [sha(uncompressed)]},
            "history": [{"created_by": "synthetic OCI import fixture"}],
        }), CONFIG)
        manifest = descriptor(encode({
            "schemaVersion": 2, "mediaType": MANIFEST, "config": config, "layers": [layer],
        }), MANIFEST)
        return manifest, config

    original, config = image("original")
    moved, moved_config = image("replacement")
    (root / "expected.json").write_bytes(encode({
        "repo": IMAGE, "manifest": original["digest"], "config": config["digest"],
        "moved_manifest": moved["digest"], "moved_config": moved_config["digest"],
    }))
    for kind, ref_name, manifest, image_config in (
        ("tag", IMAGE + ":candidate", original, config),
        ("digest", IMAGE + "@" + original["digest"], original, config),
        ("moved", IMAGE + ":candidate", moved, moved_config),
    ):
        idx = dict(manifest, annotations={
            "io.containerd.image.name": ref_name,
            "org.opencontainers.image.ref.name": ref_name,
        })
        (layout / "index.json").write_bytes(encode({"schemaVersion": 2, "manifests": [idx]}))
        with tarfile.open(root / f"oci-{kind}.tar", "w") as tar:
            for name in ("oci-layout", "index.json"):
                tar.add(layout / name, arcname=name)
            for desc in (manifest, image_config, layer):
                name = "blobs/sha256/" + desc["digest"].split(":")[1]
                tar.add(layout / name, arcname=name)


def serve(root):
    expected = json.loads((root / "expected.json").read_bytes())
    blob_root = root / "layout/blobs/sha256"
    manifests = {expected["manifest"], expected["moved_manifest"]}
    all_blobs = {
        desc["digest"] for digest in manifests
        for manifest in [json.loads((blob_root / digest.split(":")[1]).read_bytes())]
        for desc in [manifest["config"], *manifest["layers"]]
    }

    class Registry(http.server.BaseHTTPRequestHandler):
        def log_message(self, *_):
            pass

        def do_HEAD(self):
            self.respond(False)

        def do_GET(self):
            self.respond(True)

        def respond(self, send_body):
            digest = None
            if self.path == "/v2/":
                payload, media = b"{}", "application/json"
            elif self.path.startswith(f"/v2/{REPO}/manifests/"):
                key = self.path.rsplit("/", 1)[1]
                digest = expected["moved_manifest"] if key == "candidate" else key
                if digest not in manifests:
                    self.send_error(404)
                    return
                payload, media = (blob_root / digest.split(":")[1]).read_bytes(), MANIFEST
            elif self.path.startswith(f"/v2/{REPO}/blobs/"):
                digest = self.path.rsplit("/", 1)[1]
                if digest not in all_blobs:
                    self.send_error(404)
                    return
                payload, media = (blob_root / digest.split(":")[1]).read_bytes(), "application/octet-stream"
            else:
                self.send_error(404)
                return
            self.send_response(200)
            self.send_header("Docker-Distribution-API-Version", "registry/2.0")
            self.send_header("Content-Type", media)
            self.send_header("Content-Length", str(len(payload)))
            if digest:
                assert sha(payload) == digest
                self.send_header("Docker-Content-Digest", digest)
            self.end_headers()
            if send_body:
                self.wfile.write(payload)

    with http.server.ThreadingHTTPServer(("127.0.0.1", 443), Registry) as server:
        cert = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        cert.load_cert_chain(root / "tls.crt", root / "tls.key")
        server.socket = cert.wrap_socket(server.socket, server_side=True)
        server.serve_forever()


if __name__ == "__main__":
    {"create": create, "serve": serve}[sys.argv[1]](Path(sys.argv[2]))
